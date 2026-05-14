<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Survos\AiWorkflowBundle\Entity\Subject;

/**
 * @extends ServiceEntityRepository<Subject>
 */
final class SubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subject::class);
    }

    public function findOrCreate(string $subjectType, string $subjectId, ?string $scope = null): Subject
    {
        $subject = $this->findOneBy([
            'subjectType' => $subjectType,
            'subjectId'   => $subjectId,
            'scope'       => $scope,
        ]);

        if ($subject === null) {
            $subject = new Subject($subjectType, $subjectId, $scope);
            $this->getEntityManager()->persist($subject);
        }

        return $subject;
    }

    /**
     * Returns all existing subjectIds for the given type+scope as a set (id → true).
     * Used by importers to skip rows that are already in the database.
     *
     * @return array<string, true>
     */
    public function existingIdMap(string $subjectType, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.subjectId')
            ->where('s.subjectType = :type')
            ->setParameter('type', $subjectType);

        if ($scope !== null) {
            $qb->andWhere('s.scope = :scope')->setParameter('scope', $scope);
        }

        return array_fill_keys($qb->getQuery()->getSingleColumnResult(), true);
    }

    /**
     * Returns tasks run per subject keyed by subjectId.
     * Task name is extracted from source before the first '@' (e.g. "observe@1.0" → "observe").
     *
     * @param list<string> $subjectIds
     * @return array<string, list<string>>  subjectId → [taskName, ...]
     */
    public function tasksRunBySubjectId(string $subjectType, array $subjectIds, ?string $scope = null): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));

        $params = array_values($subjectIds);
        $types  = array_fill(0, count($subjectIds), ParameterType::STRING);

        $sql = "SELECT DISTINCT subject_id, source FROM claim
                WHERE subject_type = ? AND subject_id IN ($placeholders)";
        array_unshift($params, $subjectType);
        array_unshift($types, ParameterType::STRING);

        if ($scope !== null) {
            $sql .= ' AND scope = ?';
            $params[] = $scope;
            $types[]  = ParameterType::STRING;
        }

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $id   = (string) $row['subject_id'];
            $task = explode('@', (string) $row['source'])[0];
            if ($task !== '' && !in_array($task, $result[$id] ?? [], true)) {
                $result[$id][] = $task;
            }
        }

        return $result;
    }
}
