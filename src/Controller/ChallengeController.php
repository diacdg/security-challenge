<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class ChallengeController extends AbstractController
{
    const CHALLENGE_KEY = 'challenge-%d-has-solved';

    private $session;

    public function __construct(SessionInterface $session)
    {
        if (!$session->get('start')) {
            $session->set('start', date('Y-m-d H:i:s'));
        }

        $this->session = $session;
    }

    /**
     * @Route("/challenges", name="challenges_list", methods={"GET"})
     */
    public function list(): JsonResponse
    {
        return new JsonResponse($this->getChallenges());
    }

    /**
     * @Route("/validate", name="validate", methods={"POST"})
     */
    public function validate(Request $request): JsonResponse
    {
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
                            $this->saveJson($challenges);
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

    private function getChallenges(): array
    {
        $json = json_decode(file_get_contents($this->getChallengesFilePath()), true);

        if (!$json) {
            throw new \UnexpectedValueException('Invalid json file.');
        }

        return $json;
    }

    private function saveJson(array $data): bool
    {
        $file = new \SplFileObject($this->getChallengesFilePath(), "w");

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
}
