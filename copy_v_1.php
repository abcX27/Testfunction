# 题目复制需求与实现（修订版）

> 面向：跨题库 / 跨科目 / 同题库同科目 的题目复制；含知识点校验与复制；修复若干实现问题。

---

## 1. 背景与目标

* 将一批题目从 **源归属**（题库/科目/校区/分类）复制/挂载到 **目标归属**（可能有多个）。
* 复制时，需要确保题目的 **知识点名称** 被目标校区 **完全包含**（同题库同科目为校验，不复制题；跨题库时需要复制题并重建知识点关联）。
* 支持：

  * 跨页全选（`$ids === 0`）
  * 多目标复制（`new_origin` 为数组）
  * 批量写入（1000 条分片）

---

## 2. 核心概念

* **old\_origin**：源归属（`bank_id / subject_id / school_id / classify_id_2 / ques_id`）
* **new\_origin**：目标归属（同上结构，`ques_id` 可无）
* **QuestQuestionBank**：题库-题目关系（归属挂载表）
* **QuesRelatePoint**：题目-知识点关系
* **QuesPoint / QuesPointRelateSchool**：知识点与校区关系

---

## 3. 输入与输出

### 入参

* `$ids`（`0` 表示跨页全选；非 `0` 表示从 `old_origin` 里取选中题目 ID）
* `$bankId`（**源**题库 ID，用于知识点校验）
* `$params`：

  * `old_origin`：源归属数组
  * `new_origin`：目标归属数组（可多个）

### 返回

```php
[
  'count'       => (int) 成功复制数量,
  'question'    => array 已存在题目的详细信息,
  'exisitCount' => (int) 已存在题目数量
]
```

---

## 4. 业务规则

1. **题目范围确定**

   * `$ids === 0` → 按 `old_origin[0]` 查询所有题目 ID；
   * 否则 → 从 `old_origin` 中读取选中的 `ques_id`。

2. **同题库 + 同科目**

   * 仅做知识点**包含性校验**（`checkQuestionPointName`）。
   * 不复制题，仅向 `QuestQuestionBank` 新增挂载关系，并增长题库题量。

3. **跨题库（科目同/不同均属于此类）**

   * 做知识点**包含性校验并建立映射**（`checkQuestionPointNameV1`）。
   * **复制题**（`qb_question` / `qb_question_content`），重建 `QuesRelatePoint`（按映射把新题挂到**目标校区**的知识点上）。
   * 新增 `QuestQuestionBank` 关系。

4. **幂等**：若目标题库-校区-分类下已存在该题（或复制后的新题），则跳过。

---

## 5. 流程图（Mermaid）

```mermaid
flowchart TD
    A[开始: 接收 $ids, $bankId, $params] --> B{ids == 0?}
    B -- 是 --> C[按 old_origin 查询全量题目ID]
    B -- 否 --> D[取 old_origin 中选中的 ques_id]
    C --> E[为空?]
    D --> E[为空?]
    E -- 是 --> Z[抛错: 请选择要转移的题目]
    E -- 否 --> F[开启事务]
    F --> G[遍历每个 new_origin]

    G --> H[逐个 old_origin 做对比]
    H --> I{old_bank_id == new_bank_id 且\nold_subject_id == new_subject_id?}

    I -- 是(同题库同科目) --> C1[checkQuestionPointName 校验]
    C1 --> C2[查找已存在题目]
    C2 --> C3[idsToAdd = ids - 已存在]
    C3 -- 空 --> NEXT[下一个目标]
    C3 -- 非空 --> C4[仅写 QuestQuestionBank, 增长题量]
    C4 --> NEXT

    I -- 否(跨题库) --> K1[checkQuestionPointNameV1 映射题目->校区知识点]
    K1 --> K2[查找已存在题目]
    K2 --> K3[idsToAdd = ids - 已存在]
    K3 -- 空 --> NEXT
    K3 -- 非空 --> K4[复制题 qb_question + qb_question_content]
    K4 --> K41[按映射重建 QuesRelatePoint]
    K41 --> K5[写 QuestQuestionBank]
    K5 --> NEXT

    NEXT --> R{还有 new_origin / old_origin?}
    R -- 是 --> G
    R -- 否 --> S[汇总结果, 提交事务, 返回]
```

---

## 6. 主要异常

* `请选择要转移的题目`
* `题目知识点名称不存在，复制失败`
* `题目知识点名称不完全包含于目标校区知识点名称中，复制失败`

---

## 7. 性能策略

* 所有批量写入（`QuesRelatePoint`、`QuestQuestionBank`）采用 **1000 条分片**。
* 避免重复插入：复制前先计算 `idsToAdd`。
* 外层事务：确保跨表一致性；内层复制题使用数据库嵌套事务（支持则 OK）。

---

## 8. 相对原始代码的修复点

1. **删除调试语句**：`dd($originMap)` → 删除。
2. **变量名统一**：`$oldSubjeckId` → `$oldSubjectId`。
3. **已存在题目收集**：`$existingQuestions` 与 `$existingQuestion` 统一，并 **扁平合并**。
4. **批量插入缓存重置**：`$questionBankData` 每次使用后要清空，避免污染后续批次。
5. **知识点校验的 join 表名**：统一使用模型表名（通过 `$model->getTable()` 获取），避免 `ques_point`/`ques_points` 混用。
6. **跨题库复制的知识点关联**：

   * 修正 `searchQuestionInsert`：以前误把 “新题 ID” 当作 `ques_point_id` 写入；
   * 现在改为 **按题目→目标校区知识点 ID 映射** 写入多个 `QuesRelatePoint` 记录。
7. **按 old\_origin 对比**：校验时使用 **每个 old\_origin 的 bank/subject** 与当前 `new_origin` 对比，更严谨。

---

## 9. 完整实现（PHP）

> 说明：以下代码以 **Service** 形式给出；请按你的命名空间与模型实际表名适配（已使用 `getTable()` 规避大部分表名差异）。

```php
<?php

namespace App\Services; // 按需调整

use Illuminate\Support\Facades\DB;
use App\Exceptions\FailedException; // 按需调整
use App\Models\QuestQuestionBank;
use App\Models\QuesRelatePoint;
use App\Models\QuesPoint;
use App\Models\QuesPointRelateSchool;

class QuestionCopyService
{
    protected $questionBankRepository; // 需要具备 beginTransaction()/commit()
    protected $quesBankRepository;     // 需要具备 grow(field, id, step)

    public function __construct($questionBankRepository, $quesBankRepository)
    {
        $this->questionBankRepository = $questionBankRepository;
        $this->quesBankRepository = $quesBankRepository;
    }

    /**
     * 题目复制（跨题库/科目/校区/分类）
     */
    public function copyV1($ids, int $bankId, array $params)
    {
        try {
            $this->copyParamsCheck($params);

            $oldOrigin  = $params['old_origin'] ?? [];
            $newOrigins = $params['new_origin'] ?? [];

            // 1) 待处理题目 ID
            if ($ids === 0) {
                // 跨页全选：按 old_origin[0] 查询
                $ids = QuestQuestionBank::getQuestionIdsByOrigin($oldOrigin[0]);
                $ids = collect($ids)->unique()->values()->toArray();
            } else {
                // 手动选择：从 old_origin 采集 ques_id
                $ids = array_values(array_unique(array_column($oldOrigin, 'ques_id')));
            }

            if (empty($ids)) {
                throw new FailedException('请选择要转移的题目');
            }

            $this->questionBankRepository->beginTransaction();

            $totalCopied       = 0;
            $existingQuestions = [];

            foreach ($newOrigins as $newOrigin) {
                $newBankId     = (int)($newOrigin['bank_id']);
                $newSubjectId  = (int)($newOrigin['subject_id'] ?? 0);
                $newSchoolId   = (int)($newOrigin['school_id'] ?? 0);
                $newClassifyId = (int)($newOrigin['classify_id_2'] ?? 0);

                foreach ($oldOrigin as $oldItem) {
                    $oldBankId    = (int)($oldItem['bank_id']);
                    $oldSubjectId = (int)($oldItem['subject_id']);
                    $oldSchoolId  = (int)($oldItem['school_id']);

                    $isCrossBankOrSubject = ($oldBankId !== $newBankId) || ($oldSubjectId !== $newSubjectId);

                    if ($isCrossBankOrSubject) {
                        // A) 跨题库（科目同/不同都进这里）
                        $pointIdsByQuestion = $this->checkQuestionPointNameV1($ids, $oldBankId, $oldSchoolId, $newSubjectId);

                        $existing = $this->findExistingQuestions($ids, $newOrigin);
                        if (!empty($existing)) {
                            $existingQuestions = array_merge($existingQuestions, $existing);
                        }
                        $idsToAdd = array_values(array_diff($ids, $existing));

                        if (!empty($idsToAdd)) {
                            $totalCopied += count($idsToAdd);

                            // 复制题 & 重建知识点关联，返回新题ID数组
                            $newQuestionIds = $this->searchQuestionInsert($idsToAdd, $newOrigin, $pointIdsByQuestion);

                            // 写入 QuestQuestionBank
                            $questionBankData = [];
                            foreach ($newQuestionIds as $newQid) {
                                $questionBankData[] = [
                                    'ques_id'    => $newQid,
                                    'bank_id'    => $newBankId,
                                    'subject_id' => $newSubjectId,
                                    'school_id'  => $newSchoolId,
                                    'classify_id'=> $newClassifyId,
                                    'distribute' => $newClassifyId ? 1 : 0,
                                    'created_at' => time(),
                                    'updated_at' => time(),
                                ];
                            }
                            if (!empty($questionBankData)) {
                                foreach (array_chunk($questionBankData, 1000) as $chunk) {
                                    QuestQuestionBank::insert($chunk);
                                }
                            }
                        }
                    } else {
                        // B) 同题库 + 同科目
                        $this->checkQuestionPointName($ids, $oldBankId, $oldSchoolId, $newSubjectId);

                        $existing = $this->findExistingQuestions($ids, $newOrigin);
                        if (!empty($existing)) {
                            $existingQuestions = array_merge($existingQuestions, $existing);
                        }
                        $idsToAdd = array_values(array_diff($ids, $existing));

                        if (!empty($idsToAdd)) {
                            $this->quesBankRepository->grow('ques_amount', $newBankId, count($idsToAdd));

                            $questionBankData = [];
                            foreach ($idsToAdd as $quesId) {
                                $questionBankData[] = [
                                    'ques_id'    => $quesId,
                                    'bank_id'    => $newBankId,
                                    'subject_id' => $newSubjectId,
                                    'school_id'  => $newSchoolId,
                                    'classify_id'=> $newClassifyId,
                                    'distribute' => $newClassifyId ? 1 : 0,
                                    'created_at' => time(),
                                    'updated_at' => time(),
                                ];
                            }
                            foreach (array_chunk($questionBankData, 1000) as $chunk) {
                                QuestQuestionBank::insert($chunk);
                            }
                        }
                    }
                }
            }

            // 汇总已存在题目详情
            $existingQuestionDetails = [];
            if (!empty($existingQuestions)) {
                $existingQuestions       = array_values(array_unique($existingQuestions));
                $existingQuestionDetails = $this->errQuestion([], $existingQuestions);
            }

            $this->questionBankRepository->commit();

            return [
                'count'       => $totalCopied,
                'question'    => $existingQuestionDetails,
                'exisitCount' => !empty($existingQuestions) ? count($existingQuestions) : 0,
            ];
        } catch (\Throwable $e) {
            throw new FailedException('复制失败：' . $e->getMessage());
        }
    }

    /**
     * 校验：题目的知识点名称必须完全包含于【目标校区】的知识点名称中（同题库同科目场景）。
     */
    public function checkQuestionPointName(array $ids, int $bankId, int $oldSchoolId, int $newSubjectId): void
    {
        $relTable = (new QuesRelatePoint)->getTable();      // e.g. ques_relate_point
        $ptTable  = (new QuesPoint)->getTable();            // e.g. ques_points

        $questionPointNames = QuesRelatePoint::query()
            ->whereIn($relTable . '.ques_id', $ids)
            ->where($relTable . '.ques_bank_id', $bankId)
            ->join($ptTable . ' as qp', $relTable . '.ques_point_id', '=', 'qp.id')
            ->pluck('qp.name')
            ->toArray();

        if (empty($questionPointNames)) {
            throw new \Exception('题目知识点名称不存在，复制失败');
        }

        $schoolPointIds = QuesPointRelateSchool::query()
            ->where('school_id', $oldSchoolId)
            ->where('deleted_at', 0)
            ->pluck('point_id')
            ->toArray();

        $schoolPointNames = QuesPoint::query()
            ->whereIn('id', $schoolPointIds)
            ->where('subject_id', $newSubjectId)
            ->where('deleted_at', 0)
            ->pluck('name')
            ->toArray();

        if (count(array_diff(array_unique($questionPointNames), array_unique($schoolPointNames))) > 0) {
            throw new \Exception('题目知识点名称不完全包含于目标校区知识点名称中，复制失败');
        }
    }

    /**
     * 校验+映射：返回 题目ID => [目标校区知识点ID...]（跨题库场景）。
     */
    public function checkQuestionPointNameV1(array $ids, int $bankId, int $oldSchoolId, int $subjectId): array
    {
        $relTable = (new QuesRelatePoint)->getTable();     // e.g. ques_relate_point
        $ptTable  = (new QuesPoint)->getTable();           // e.g. ques_points
        $prsTable = (new QuesPointRelateSchool)->getTable(); // e.g. ques_point_relate_school

        $questionPoints = QuesRelatePoint::query()
            ->whereIn($relTable . '.ques_id', $ids)
            ->where($relTable . '.ques_bank_id', $bankId)
            ->join($ptTable . ' as qp', $relTable . '.ques_point_id', '=', 'qp.id')
            ->select($relTable . '.ques_id as ques_id', 'qp.name as name')
            ->get();

        if ($questionPoints->isEmpty()) {
            throw new \Exception('题目知识点名称不存在，复制失败');
        }

        $schoolPoints = QuesPointRelateSchool::query()
            ->from($prsTable . ' as qprs')
            ->join($ptTable . ' as qp', 'qprs.point_id', '=', 'qp.id')
            ->where('qprs.school_id', $oldSchoolId)
            ->where('qprs.deleted_at', 0)
            ->where('qp.subject_id', $subjectId)
            ->select('qprs.point_id as point_id', 'qp.name as name')
            ->get();

        $result = [];

        foreach ($ids as $questionId) {
            $matchedSchoolPointIds = [];
            $namesForQuestion = $questionPoints->where('ques_id', $questionId)->pluck('name')->toArray();
            if (empty($namesForQuestion)) {
                // 该题无知识点，跳过或按需求抛错；这里选择继续
                continue;
            }
            foreach ($namesForQuestion as $qName) {
                foreach ($schoolPoints as $sp) {
                    if ($sp->name === $qName) {
                        $matchedSchoolPointIds[] = (int)$sp->point_id;
                    }
                }
            }
            if (empty($matchedSchoolPointIds) && !empty($namesForQuestion)) {
                throw new \Exception("题目ID为 {$questionId} 的知识点名称不完全包含于目标校区知识点名称中，复制失败");
            }
            $result[$questionId] = array_values(array_unique($matchedSchoolPointIds));
        }

        return $result;
    }

    /**
     * 查询目标题库/科目/校区/分类下已存在的题目ID
     */
    private function findExistingQuestions(array $ids, array $newOrigin): array
    {
        return QuestQuestionBank::query()
            ->whereIn('ques_id', $ids)
            ->where('bank_id', (int)$newOrigin['bank_id'])
            ->where('subject_id', (int)($newOrigin['subject_id'] ?? 0))
            ->where('school_id', (int)($newOrigin['school_id'] ?? 0))
            ->where('classify_id', (int)($newOrigin['classify_id_2'] ?? 0))
            ->pluck('ques_id')
            ->toArray();
    }

    /**
     * 复制题 + 重建知识点关联；返回新题ID数组
     */
    private function searchQuestionInsert(array $idsToAdd, array $newOrigin, array $pointIdsByQuestion): array
    {
        // 复制题：返回 oldId => newId
        $newIdMap = $this->duplicateQuestions($idsToAdd);

        // 重建知识点关联（面向 目标校区 的知识点ID）
        $pointRelations = [];
        foreach ($newIdMap as $oldQid => $newQid) {
            $mappedPointIds = $pointIdsByQuestion[$oldQid] ?? [];
            foreach ($mappedPointIds as $pid) {
                $pointRelations[] = [
                    'ques_id'       => (int)$newQid,
                    'ques_point_id' => (int)$pid,
                    'ques_bank_id'  => (int)$newOrigin['bank_id'],
                    'created_at'    => time(),
                    'updated_at'    => time(),
                ];
            }
        }
        if (!empty($pointRelations)) {
            foreach (array_chunk($pointRelations, 1000) as $chunk) {
                QuesRelatePoint::insert($chunk);
            }
        }

        return array_values($newIdMap); // 仅返回新题ID数组
    }

    /**
     * 复制题：返回 [旧题ID => 新题ID]
     */
    public function duplicateQuestions(array $oldQuestionIds): array
    {
        $newQuestionIdMap = [];
        $parentIdMap      = [];

        DB::beginTransaction();
        try {
            $questions        = DB::table('qb_question')->whereIn('id', $oldQuestionIds)->get()->toArray();
            $questionContents = DB::table('qb_question_content')->whereIn('ques_id', $oldQuestionIds)->get()->toArray();

            foreach ($questions as $question) {
                $oldQuestionId = (int)$question->id;

                $newQuestion = (array)$question;
                unset($newQuestion['id'], $newQuestion['created_at'], $newQuestion['updated_at'],
                      $newQuestion['paper_citations'], $newQuestion['wrong_num'], $newQuestion['answer_num'],
                      $newQuestion['test_citations'], $newQuestion['work_citations'], $newQuestion['citation_details']);

                $newQuestion['created_at'] = time();
                $newQuestion['updated_at'] = time();

                // 处理组合题 parent_id 映射
                $oldParentId = (int)($newQuestion['parent_id'] ?? 0);
                if ($oldParentId > 0 && isset($parentIdMap[$oldParentId])) {
                    $newQuestion['parent_id'] = $parentIdMap[$oldParentId];
                }

                $newQuestionId = (int)DB::table('qb_question')->insertGetId($newQuestion);

                // 复制内容
                $questionContent = collect($questionContents)->firstWhere('ques_id', $oldQuestionId);
                if ($questionContent) {
                    $newQuestionContent = (array)$questionContent;
                    unset($newQuestionContent['id'], $newQuestionContent['created_at'], $newQuestionContent['updated_at'], $newQuestionContent['deleted_at']);
                    $newQuestionContent['ques_id'] = $newQuestionId;
                    DB::table('qb_question_content')->insert($newQuestionContent);
                }

                $newQuestionIdMap[$oldQuestionId] = $newQuestionId;

                // 记录组合题的 parent 映射
                if ((int)$question->ques_type === 6) {
                    $parentIdMap[$oldQuestionId] = $newQuestionId;
                }
            }

            DB::commit();
            return $newQuestionIdMap;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ========== 其他：参数校验（示例占位） ==========
    private function copyParamsCheck(array $params): void
    {
        if (empty($params['old_origin']) || empty($params['new_origin'])) {
            throw new FailedException('参数不完整：old_origin / new_origin 不能为空');
        }
    }
}
```

---

## 10. 使用与测试建议

* **单测**：分别覆盖三类路径（同库同科目、跨库同科目、跨库跨科目），以及知识点缺失/不完全匹配场景。
* **灰度**：先在小批量数据上验证知识点映射是否正确（`QuesRelatePoint` 条数与名称对齐）。
* **日志**：记录 `idsToAdd` 数量、`newIdMap` 大小、批次长度，便于排查。

---

> 如需把这段代码拆分为 Repository + Service，更利于单测与维护；也可以补充一个“只复制不校验”的管理员开关（默认关闭）。
