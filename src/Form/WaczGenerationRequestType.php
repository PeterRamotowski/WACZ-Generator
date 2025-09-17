<?php

namespace App\Form;

use App\DTO\WaczGenerationRequestDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WaczGenerationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'label' => 'form.url.label',
                'help' => 'form.url.help',
                'attr' => [
                    'placeholder' => 'placeholders.example_url',
                    'class' => 'form-input'
                ],
                'required' => true,
            ])
            ->add('title', TextType::class, [
                'label' => 'form.title.label',
                'help' => 'form.title.help',
                'attr' => [
                    'placeholder' => 'placeholders.archive_title',
                    'class' => 'form-input'
                ],
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'form.description.label',
                'help' => 'form.description.help',
                'attr' => [
                    'placeholder' => 'placeholders.archive_description',
                    'rows' => 3,
                    'class' => 'form-textarea'
                ],
                'required' => false,
            ])
            ->add('maxDepth', IntegerType::class, [
                'label' => 'form.max_depth.label',
                'help' => 'form.max_depth.help',
                'attr' => [
                    'min' => 1,
                    'max' => 10,
                    'class' => 'form-input'
                ],
                'data' => 10,
            ])
            ->add('maxPages', IntegerType::class, [
                'label' => 'form.max_pages.label',
                'help' => 'form.max_pages.help',
                'attr' => [
                    'min' => 1,
                    'max' => 10000,
                    'class' => 'form-input'
                ],
                'data' => 100,
            ])
            ->add('crawlDelay', IntegerType::class, [
                'label' => 'form.crawl_delay.label',
                'help' => 'form.crawl_delay.help',
                'attr' => [
                    'min' => 500,
                    'max' => 30000,
                    'step' => 100,
                    'class' => 'form-input'
                ],
                'data' => 1000,
            ])
            ->add('followExternalLinks', CheckboxType::class, [
                'label' => 'form.follow_external_links.label',
                'help' => 'form.follow_external_links.help',
                'required' => false,
                'attr' => [
                    'class' => 'form-checkbox'
                ],
            ])
            ->add('includeImages', CheckboxType::class, [
                'label' => 'form.include_images.label',
                'help' => 'form.include_images.help',
                'required' => false,
                'data' => true,
                'attr' => [
                    'class' => 'form-checkbox'
                ],
            ])
            ->add('includeCSS', CheckboxType::class, [
                'label' => 'form.include_css.label',
                'help' => 'form.include_css.help',
                'required' => false,
                'data' => true,
                'attr' => [
                    'class' => 'form-checkbox'
                ],
            ])
            ->add('includeJS', CheckboxType::class, [
                'label' => 'form.include_js.label',
                'help' => 'form.include_js.help',
                'required' => false,
                'data' => true,
                'attr' => [
                    'class' => 'form-checkbox'
                ],
            ])
            ->add('excludeUrls', CollectionType::class, [
                'label' => 'form.exclude_urls.label',
                'help' => 'form.exclude_urls.help',
                'entry_type' => UrlType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => [
                        'placeholder' => 'placeholders.enter_url_to_exclude',
                        'class' => 'form-input'
                    ],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'required' => false,
                'attr' => [
                    'class' => 'collection-container'
                ],
            ])
            ->add('excludePatterns', CollectionType::class, [
                'label' => 'form.exclude_patterns.label',
                'help' => 'form.exclude_patterns.help',
                'entry_type' => TextType::class,
                'entry_options' => [
                    'label' => false,
                    'attr' => [
                        'placeholder' => 'placeholders.enter_pattern_to_exclude',
                        'class' => 'form-input'
                    ],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
                'required' => false,
                'attr' => [
                    'class' => 'collection-container'
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'form.submit.label',
                'attr' => [
                    'class' => 'btn btn-primary btn-lg w-full'
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WaczGenerationRequestDTO::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wacz_generation_request',
        ]);
    }
}
