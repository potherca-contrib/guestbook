<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Conference;
use App\Form\CommentFormType;
use App\Repository\{CommentRepository, ConferenceRepository};
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\{RedirectResponse, Response, Request};
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;
use Twig\Error\{LoaderError, RuntimeError, SyntaxError};

class ConferenceController extends AbstractController
{
    private $twig;
    private $entityManager;

    public function __construct(Environment $twig, EntityManagerInterface $entityManager)
    {
        $this->twig = $twig;
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/", name="homepage")
     *
     * @param ConferenceRepository $conferenceRepository
     *
     * @return Response
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function index(ConferenceRepository $conferenceRepository): Response
    {
        return new Response($this->twig->render('conference/index.html.twig', [
            'conferences' => $conferenceRepository->findAll()
        ]));
    }

    /**
     * @Route("/conference/{slug}", name="conference")
     *
     * @param Request $request
     * @param Conference $conference
     * @param CommentRepository $commentRepository
     * @param SpamChecker $spamChecker
     * @param string $photoDir
     *
     * @return Response
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws Exception
     */
    public function show(Request $request, Conference $conference, CommentRepository $commentRepository, SpamChecker $spamChecker, string $photoDir): Response
    {
        $comment = new Comment();
        $form = $this->createForm(CommentFormType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->handleFormSubmission($conference, $form, $comment, $request, $photoDir, $spamChecker);
        }

        $offset = max(0, $request->query->getInt('offset', 0));
        $paginator = $commentRepository->getCommentPaginator($conference, $offset);

        return new Response($this->twig->render('conference/show.html.twig', [
            'conference' => $conference,
            'comments' => $paginator,
            'previous' => $offset - CommentRepository::PAGINATOR_PER_PAGE,
            'next' => min(count($paginator), $offset + CommentRepository::PAGINATOR_PER_PAGE),
            'comment_form' => $form->createView(),
        ]));
    }

    private function handleFormSubmission(Conference $conference, FormInterface $form, Comment $comment, Request $request, string $photoDir, SpamChecker $spamChecker): RedirectResponse
    {
        $comment->setConference($conference);

        $this->setPhoto($form, $photoDir, $comment);

        $this->entityManager->persist($comment);

        $context = [
            'user_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('user-agent'),
            'referrer' => $request->headers->get('referrer'),
            'permalink' => $request->getUri(),
        ];

        if ($spamChecker->getSpamScore($comment, $context) === 2) {
            throw new RuntimeException('Blatant spam, go away!');
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('conference', ['slug' => $conference->getSlug()]);
    }

    private function setPhoto(FormInterface $form, string $photoDir, Comment $comment)
    {
        $photo = $form['photo']->getData();

        if ($photo) {
            try {
                $filename = bin2hex(random_bytes(6)) . '.' . $photo->guessExtension();
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                throw new RuntimeException("Failed to generate random bytes. {$errorMessage}");
            }

            try {
                $photo->move($photoDir, $filename);
            } catch (FileException $e) {
                $errorMessage = $e->getMessage();
                throw new RuntimeException("Failed to upload photo. {$errorMessage}");
            }

            $comment->setPhotoFilename($filename);
        }
    }
}
