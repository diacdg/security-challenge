<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class ChallengeController extends AbstractController
{
    const CHALLENGE_KEY = 'challenge-%d-has-solved';

    private $session;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * @Route("/challenges", name="challenges_list", methods={"GET"})
     */
    public function list(): JsonResponse
    {
        $challengesList = $this->getChallenges();
        $solversList = $this->getSolvers();
        $currentUser = $this->getCurrentUserId();
        $lastSolved = -1;

        foreach ($solversList as $solver => &$solvedTasks) {
            foreach ($solvedTasks as &$task) {
                $challengesList[$task]['solveCount']++;

                if ($solver !== $currentUser) break;

                $challengesList[$task]['solved'] = true;
                // for next task logic
                $lastSolved = $task;
            }
        }

        foreach ($challengesList as $challengeId => &$challenge) {
            if ($challengeId > $lastSolved + 1) {
                // reset descriptions for unavailable tasks, only the next
                // task should be available to read
                $challenge['description'] = '';
            } else {
                $challenge['usable'] = true;
            }
        }
        return new JsonResponse($challengesList);
    }

    /**
     * @Route("/validate", name="validate", methods={"POST"})
     */
    public function validate(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), true);

        if (!$this->isValid($data)) {
            return new JsonResponse(['error' => 'Invalid request data.'], 400);
        }

        if ($data['flag'] == 'valid-flag') {
            $challengeKey = sprintf(static::CHALLENGE_KEY, $data['id']);

            if (!$this->session->has($challengeKey)) {
                $challenges = $this->getChallenges();

                foreach ($challenges as &$challenge) {
                    if ($challenge['id'] == $data['id']) {
                        $this->session->set($challengeKey, date('Y-m-d H:i:s'));

                        if ($challenge['has_solved'] == false) {
                            $challenge['has_solved'] = true;
                            $this->saveChallengesJson($challenges);
                        }

                        break;
                    }
                }
            }

            return new JsonResponse(['message' => 'challenge has solved']);
        }

        return new JsonResponse(['message' => 'invalid flag']);
    }

    private function isValid($data): bool
    {
        if (empty($data) && !is_array($data)) {
            return false;
        }

        $isIdValid = !empty($data['id']) && is_int($data['id']);
        $isFlagValid = !empty($data['flag']) && is_string($data['flag']);

        return $isIdValid && $isFlagValid;
    }

    private function getChallengesFilePath(): string
    {
        $rootDir = $this->getParameter('kernel.project_dir');

        return $rootDir.'/challenges.json';
    }

    private function getSolversFilePath(): string
    {
        $rootDir = $this->getParameter('kernel.project_dir');

        return $rootDir.'/solvers.json';
    }

    private function loadJson(string $path): array
    {
        $json = json_decode(file_get_contents($path), true);

        if (!$json) {
            throw new \UnexpectedValueException('Invalid json file.');
        }

        return $json;
    }

    private function getChallenges(): array
    {
        return $this->loadJson($this->getChallengesFilePath());
    }

    private function getSolvers(): array
    {
        return $this->loadJson($this->getSolversFilePath());
    }

    private function saveJson(string $path, array $data): bool
    {
        $file = new \SplFileObject($path, "w");

        for ($tries = 0; $tries <= 10; $tries++) {
            if ($file->flock(LOCK_EX)) {
                $file->ftruncate(0);
                $file->fwrite(json_encode($data));
                $file->flock(LOCK_UN);

                return true;
            }

            sleep(1);
        }

        return false;
    }

    private function saveChallengesJson(array $data): bool
    {
        return $this->saveJson($this->getChallengesFilePath(), $data);
    }

    private function saveSolversJson(array $data): bool
    {
        return $this->saveJson($this->getSolversFilePath(), $data);
    }

    private function getCurrentUserId(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request->headers->get('X-Solver-Id');
    }
}
