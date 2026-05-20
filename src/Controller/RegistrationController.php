<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\DeveloperProfile;
use App\Entity\RecruiterProfile;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier)
    {
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger,
        NotificationManager $notificationManager,
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $accountType = $form->get('accountType')->getData();
            $firstName = trim((string) $form->get('firstName')->getData());
            $lastName = trim((string) $form->get('lastName')->getData());
            $companyName = trim((string) $form->get('companyName')->getData());
            $workEmail = trim((string) $form->get('workEmail')->getData());

            // Server-side validation of recruiter-specific required fields
            if ('recruiter' === $accountType) {
                $hasError = false;
                if ('' === $companyName) {
                    $this->addFlash('error', 'Le nom de l\'entreprise est requis pour un compte recruteur.');
                    $hasError = true;
                }
                if ('' === $workEmail) {
                    $this->addFlash('error', 'L\'email professionnel est requis pour un compte recruteur.');
                    $hasError = true;
                }
                if ($hasError) {
                    return $this->render('registration/register.html.twig', [
                        'registrationForm' => $form,
                    ]);
                }
            }

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $user->setStatus(UserStatus::PENDING);

            if ('recruiter' === $accountType) {
                $user->setRoles(['ROLE_RECRUITER']);

                $company = new Company();
                $company->setName($companyName);
                $entityManager->persist($company);

                $recruiterProfile = new RecruiterProfile();
                $recruiterProfile->setFirstName($firstName);
                $recruiterProfile->setLastName($lastName);
                $recruiterProfile->setJobTitle('');
                $recruiterProfile->setWorkEmail('' !== $workEmail ? $workEmail : null);
                $recruiterProfile->setCompany($company);
                $recruiterProfile->setUser($user);
                $entityManager->persist($recruiterProfile);
            } else {
                $user->setRoles(['ROLE_APPLICANT']);

                $baseSlug = strtolower((string) $slugger->slug($firstName . ' ' . $lastName));
                $slug = $baseSlug . '-' . bin2hex(random_bytes(4));

                $developerProfile = new DeveloperProfile();
                $developerProfile->setFirstName($firstName);
                $developerProfile->setLastName($lastName);
                $developerProfile->setHeadline('');
                $developerProfile->setSlug($slug);
                $developerProfile->setUser($user);
                $entityManager->persist($developerProfile);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            if (isset($company)) {
                $notificationManager->notifyAdminsCompanyCreated($user, $company);
            }
            $notificationManager->notifyAdminsNewPendingAccount($user);

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation(
                'app_verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address('mailer@devspot.com', 'DevSpot Mail Bot'))
                    ->to((string) $user->getEmail())
                    ->subject('Veuillez confirmer votre email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );

            $this->addFlash('success', 'Compte en attente de vérification administrateur.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator, UserRepository $userRepository): Response
    {
        $id = $request->query->get('id');
        if (null === $id) {
            $this->addFlash('verify_email_error', 'Lien de vérification invalide.');

            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find($id);
        if (!$user instanceof User) {
            $this->addFlash('verify_email_error', 'Utilisateur introuvable.');

            return $this->redirectToRoute('app_register');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        $this->addFlash('success', 'Email confirmé. Votre compte est en attente de validation par un administrateur.');

        return $this->redirectToRoute('app_login');
    }
}
