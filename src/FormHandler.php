<?php

declare(strict_types=1);

/**
 * This file is part of the FormHandler package.
 *
 * (c) SolidWorx <open-source@solidworx.co>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SolidWorx\FormHandler;

use ProxyManager\Proxy\ProxyInterface;
use SolidWorx\FormHandler\Event\FormHandlerEvent;
use SolidWorx\FormHandler\Event\FormHandlerEvents;
use SolidWorx\FormHandler\Exception\InvalidHandlerException;
use SolidWorx\FormHandler\Exception\NonUniqueHandlerException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FormHandler
{
    /**
     * @var Request|null
     */
    private $request;

    /**
     * @var FormFactoryInterface
     */
    private $factory;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var FormHandlerInterface[]
     */
    private $handlers = [];

    public function __construct(RequestStack $requestStack, EventDispatcherInterface $dispatcher, FormFactoryInterface $factory)
    {
        $this->request = $requestStack->getCurrentRequest();
        $this->factory = $factory;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @throws NonUniqueHandlerException
     */
    public function registerHandler(FormHandlerInterface $handler): void
    {
        if (true === $handler instanceof ProxyInterface) {
            $class = get_parent_class($handler);
        } else {
            $class = get_class($handler);
        }

        if (isset($this->handlers[$class])) {
            throw new NonUniqueHandlerException($handler);
        }

        $this->handlers[$class] = $handler;
    }

    /**
     * @param string|FormHandlerInterface $class
     * @param array<mixed>                $options
     *
     * @throws \Exception
     */
    public function handle($class, array $options = []): FormRequest
    {
        $optionsResolver = new OptionsResolver();

        $handler = $this->getHandler($class);

        if ($handler instanceof FormHandlerOptionsResolver) {
            $handler->configureOptions($optionsResolver);

            $options = $optionsResolver->resolve($options);
        }

        $config = Options::fromArray($options);

        $form = $this->getForm($handler, $config);
        $formRequest = new FormRequest($form, $this->request, new Response(), $config);

        $form->handleRequest($this->request);

        if (!$form->isSubmitted()) {
            if ($handler instanceof FormHandlerResponseInterface) {
                $formRequest->setResponse($handler->getResponse($formRequest));
            }

            return $formRequest;
        }

        $event = new FormHandlerEvent($handler, $form, $formRequest);

        if ($form->isValid()) {
            $this->dispatcher->dispatch(FormHandlerEvents::EVENT_FORM_SUCCESS, $event);
        } else {
            $this->dispatcher->dispatch(FormHandlerEvents::EVENT_FORM_FAIL, $event);
        }

        if ($response = $event->getResponse()) {
            $formRequest->setResponse($response);
        } elseif ($handler instanceof FormHandlerResponseInterface) {
            $formRequest->setResponse($handler->getResponse($formRequest));
        }

        return $formRequest;
    }

    /**
     * @param string|FormHandlerInterface $handler
     *
     * @throws InvalidHandlerException
     */
    private function getHandler($handler): FormHandlerInterface
    {
        if (is_string($handler)) {
            if (!isset($this->handlers[$handler])) {
                $handler = new $handler();
            } else {
                $handler = $this->handlers[$handler];
            }
        }

        if (!$handler instanceof FormHandlerInterface) {
            throw new InvalidHandlerException(FormHandlerInterface::class, $handler);
        }

        return $handler;
    }

    /**
     * @param Options<string, mixed> $options
     *
     * @throws \Exception
     *
     * @return FormInterface<FormInterface>
     */
    private function getForm(FormHandlerInterface $handler, Options $options): FormInterface
    {
        $form = $handler->getForm($this->factory, $options);

        if (!$form instanceof FormInterface && !is_string($form)) {
            throw new \Exception(sprintf('%s::getForm() must return a string or instance of FormInterface, %s given', get_class($handler), is_object($form) ? get_class($form) : gettype($form)));
        }

        if (is_string($form)) {
            $form = $this->factory->create($form);
        }

        return $form;
    }
}
