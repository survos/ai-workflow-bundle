<?php

declare(strict_types=1);

namespace Survos\AiWorkflowBundle\Controller;

use Survos\AiWorkflowBundle\Entity\Subject;
use Survos\AiWorkflowBundle\Repository\SubjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ai-workflow/subjects')]
final class SubjectController extends AbstractController
{
    public function __construct(
        private readonly SubjectRepository $subjectRepository,
    ) {
    }

    #[Route('', name: 'survos_ai_workflow_subjects')]
    public function index(Request $request): Response
    {
        $scope       = $request->query->get('scope');
        $subjectType = $request->query->get('subjectType');
        $marking     = $request->query->get('marking');

        $qb = $this->subjectRepository->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC');

        if ($scope !== null && $scope !== '') {
            $qb->andWhere('s.scope = :scope')->setParameter('scope', $scope);
        }
        if ($subjectType !== null && $subjectType !== '') {
            $qb->andWhere('s.subjectType = :subjectType')->setParameter('subjectType', $subjectType);
        }
        if ($marking !== null && $marking !== '') {
            $qb->andWhere('s.marking = :marking')->setParameter('marking', $marking);
        }

        /** @var Subject[] $subjects */
        $subjects = $qb->setMaxResults(100)->getQuery()->getResult();

        // Tasks run per subject (single query for all subjects)
        $subjectIds = array_map(fn(Subject $s) => $s->getWorkflowSubjectId(), $subjects);
        $subjectType = $subjectType ?? ($subjects[0]->subjectType ?? null);
        $tasksRun = $subjectIds
            ? $this->subjectRepository->tasksRunBySubjectId(
                Subject::class,
                $subjectIds,
                $scope ?: null,
            )
            : [];

        // Distinct filter options
        $scopes = array_column(
            $this->subjectRepository->createQueryBuilder('s')
                ->select('DISTINCT s.scope')->orderBy('s.scope')->getQuery()->getScalarResult(),
            'scope',
        );
        $subjectTypes = array_column(
            $this->subjectRepository->createQueryBuilder('s')
                ->select('DISTINCT s.subjectType')->orderBy('s.subjectType')->getQuery()->getScalarResult(),
            'subjectType',
        );
        $markings = array_column(
            $this->subjectRepository->createQueryBuilder('s')
                ->select('DISTINCT s.marking')->orderBy('s.marking')->getQuery()->getScalarResult(),
            'marking',
        );

        return $this->render('@SurvosAiWorkflow/subject/index.html.twig', [
            'subjects'        => $subjects,
            'tasksRun'        => $tasksRun,
            'scopes'          => array_filter($scopes),
            'subjectTypes'    => $subjectTypes,
            'markings'        => $markings,
            'activeScope'     => $scope,
            'activeType'      => $subjectType,
            'activeMarking'   => $marking,
        ]);
    }

    #[Route('/{id}', name: 'survos_ai_workflow_subject', requirements: ['id' => '[0-9A-Z]{26}'])]
    public function show(Subject $subject): Response
    {
        return $this->render('@SurvosAiWorkflow/subject/show.html.twig', [
            'subject' => $subject,
        ]);
    }
}
