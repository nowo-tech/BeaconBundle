<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\PasswordToggleBundle\Form\Type\PasswordType as TogglePasswordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Username / password form used by the demo firewall.
 */
final class LoginFormType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', TextType::class, [
                'label' => 'Username',
                'attr'  => [
                    'autocomplete' => 'username',
                    'class'        => 'form-control',
                    'placeholder'  => 'debugger',
                ],
            ])
            ->add('_password', TogglePasswordType::class, [
                'label' => 'Password',
                'attr'  => [
                    'autocomplete' => 'current-password',
                    'class'        => 'form-control',
                ],
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'action'          => '/login',
            'method'          => 'POST',
            'csrf_protection' => false,
        ]);
    }

    /**
     * Empty prefix so field names match Symfony Security expectations.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
