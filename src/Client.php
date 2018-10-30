<?php

/*
 * This file is part of Raven.
 *
 * (c) Sentry Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sentry;

use Sentry\Breadcrumbs\Breadcrumb;
use Sentry\Context\RuntimeContext;
use Sentry\Context\ServerOsContext;
use Sentry\Middleware\MiddlewareStack;
use Sentry\State\Scope;
use Sentry\Transport\TransportInterface;
use Zend\Diactoros\ServerRequestFactory;

/**
 * Default implementation of the {@see ClientInterface} interface.
 *
 * @author Stefano Arlandini <sarlandini@alice.it>
 */
class Client implements ClientInterface
{
    /**
     * The version of the library.
     */
    const VERSION = '2.0.x-dev';

    /**
     * The version of the protocol to communicate with the Sentry server.
     */
    const PROTOCOL_VERSION = '7';

    /**
     * The identifier of the SDK.
     */
    const SDK_IDENTIFIER = 'sentry.php';

    /**
     * This constant defines the client's user-agent string.
     */
    const USER_AGENT = self:: SDK_IDENTIFIER . '/' . self::VERSION;

    /**
     * This constant defines the maximum length of the message captured by the
     * message SDK interface.
     */
    const MESSAGE_MAX_LENGTH_LIMIT = 1024;

    /**
     * Debug log levels.
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_FATAL = 'fatal';

    /**
     * @var string[]|null
     */
    public $severityMap;

    /**
     * @var Serializer The serializer
     */
    private $serializer;

    /**
     * @var ReprSerializer The representation serializer
     */
    private $representationSerializer;

    /**
     * @var Options The client options
     */
    private $options;

    /**
     * @var TransactionStack The transaction stack
     */
    private $transactionStack;

    /**
     * @var TransportInterface The transport
     */
    private $transport;

    /**
     * @var RuntimeContext The runtime context
     */
    private $runtimeContext;

    /**
     * @var ServerOsContext The server OS context
     */
    private $serverOsContext;

    /**
     * @var MiddlewareStack The stack of middlewares to compose an event to send
     */
    private $middlewareStack;

    /**
     * Constructor.
     *
     * @param Options            $options   The client configuration
     * @param TransportInterface $transport The transport
     */
    public function __construct(Options $options, TransportInterface $transport)
    {
        $this->options = $options;
        $this->transport = $transport;
        $this->runtimeContext = new RuntimeContext();
        $this->serverOsContext = new ServerOsContext();
        $this->transactionStack = new TransactionStack();
        $this->serializer = new Serializer($this->options->getMbDetectOrder());
        $this->representationSerializer = new ReprSerializer($this->options->getMbDetectOrder());
        $this->middlewareStack = new MiddlewareStack(function (Event $event) {
            return $event;
        });

        $request = ServerRequestFactory::fromGlobals();
        $serverParams = $request->getServerParams();

        if (isset($serverParams['PATH_INFO'])) {
            $this->transactionStack->push($serverParams['PATH_INFO']);
        }

        if ($this->options->getSerializeAllObjects()) {
            $this->setAllObjectSerialize(true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function addBreadcrumb(Breadcrumb $breadcrumb, ?Scope $scope = null): void
    {
        $beforeBreadcrumbCallback = $this->options->getBeforeBreadcrumbCallback();
        $maxBreadcrumbs = $this->options->getMaxBreadcrumbs();

        if ($maxBreadcrumbs <= 0) {
            return;
        }

        $breadcrumb = $beforeBreadcrumbCallback($breadcrumb);

        if (null !== $breadcrumb && null !== $scope) {
            $scope->addBreadcrumb($breadcrumb, $maxBreadcrumbs);
        }
    }

    /**
     * Assembles an event and prepares it to be sent of to Sentry.
     *
     * @param array      $payload
     * @param null|Scope $scope
     *
     * @return null|Event
     */
    protected function prepareEvent(array $payload, ?Scope $scope = null): ?Event
    {
        $event = new Event();

        $event->setServerName($this->getOptions()->getServerName());
        $event->setRelease($this->getOptions()->getRelease());
        $event->setEnvironment($this->getOptions()->getCurrentEnvironment());

        if (isset($payload['transaction'])) {
            $event->setTransaction($payload['transaction']);
        } else {
            $event->setTransaction($this->transactionStack->peek());
        }

        if (isset($payload['logger'])) {
            $event->setLogger($payload['logger']);
        }

        // TODO these should be integrations -> eventprocessors on the scope
        $event = $this->middlewareStack->executeStack(
            $event,
            isset($_SERVER['REQUEST_METHOD']) && \PHP_SAPI !== 'cli' ? ServerRequestFactory::fromGlobals() : null,
            isset($payload['exception']) ? $payload['exception'] : null,
            $payload
        );

        if (null !== $scope) {
            $event = $scope->applyToEvent($event);
        }

        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function captureMessage(string $message, ?Severity $level = null, ?Scope $scope = null): ?string
    {
        $payload['message'] = $message;

        return $this->captureEvent($payload);
    }

    /**
     * {@inheritdoc}
     */
    public function captureException(\Throwable $exception, ?Scope $scope = null): ?string
    {
        $payload['exception'] = $exception;

        return $this->captureEvent($payload);
    }

    /**
     * {@inheritdoc}
     */
    public function captureEvent(array $payload, ?Scope $scope = null): ?string
    {
        if ($event = $this->prepareEvent($payload, $scope)) {
            return $this->send($event);
        }

        return $event;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Event $event): ?string
    {
        // TODO move this into an integration
//        if (mt_rand(1, 100) / 100.0 > $this->options->getSampleRate()) {
//            return;
//        }

        return $this->transport->send($event);
    }

    // TODO -------------------------------------------------------------------------
    // These functions shouldn't probably not live on the client

    /**
     * {@inheritdoc}
     */
    public function getTransactionStack()
    {
        return $this->transactionStack;
    }

    /**
     * {@inheritdoc}
     */
    public function addMiddleware(callable $middleware, $priority = 0)
    {
        $this->middlewareStack->addMiddleware($middleware, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function removeMiddleware(callable $middleware)
    {
        $this->middlewareStack->removeMiddleware($middleware);
    }

    /**
     * {@inheritdoc}
     */
    public function setAllObjectSerialize($value)
    {
        $this->serializer->setAllObjectSerialize($value);
        $this->representationSerializer->setAllObjectSerialize($value);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepresentationSerializer()
    {
        return $this->representationSerializer;
    }

    /**
     * {@inheritdoc}
     */
    public function setRepresentationSerializer(ReprSerializer $representationSerializer)
    {
        $this->representationSerializer = $representationSerializer;
    }

    /**
     * {@inheritdoc}
     */
    public function getSerializer()
    {
        return $this->serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function setSerializer(Serializer $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function translateSeverity(int $severity)
    {
        if (\is_array($this->severityMap) && isset($this->severityMap[$severity])) {
            return $this->severityMap[$severity];
        }

        switch ($severity) {
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
            case E_WARNING:
            case E_USER_WARNING:
            case E_RECOVERABLE_ERROR:
                return self::LEVEL_WARNING;
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_CORE_WARNING:
            case E_COMPILE_ERROR:
            case E_COMPILE_WARNING:
                return self::LEVEL_FATAL;
            case E_USER_ERROR:
                return self::LEVEL_ERROR;
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_STRICT:
                return self::LEVEL_INFO;
            default:
                return self::LEVEL_ERROR;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function registerSeverityMap($map)
    {
        $this->severityMap = $map;
    }

    /**
     * {@inheritdoc}
     */
    public function getRuntimeContext()
    {
        return $this->runtimeContext;
    }

    /**
     * {@inheritdoc}
     */
    public function getServerOsContext()
    {
        return $this->serverOsContext;
    }
}