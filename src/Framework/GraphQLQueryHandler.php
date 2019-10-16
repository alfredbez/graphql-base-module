<?php declare(strict_types=1);

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

namespace OxidEsales\GraphQL\Framework;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use OxidEsales\GraphQL\Exception\HttpErrorInterface;
use Psr\Log\LoggerInterface;

class GraphQLQueryHandler implements GraphQLQueryHandlerInterface
{

    /** @var LoggerInterface  */
    private $logger;

    /** @var SchemaFactoryInterface  */
    private $schemaFactory;

    /** @var ErrorCodeProviderInterface  */
    private $errorCodeProvider;

    /** @var  RequestReaderInterface */
    private $requestReader;

    /** @var  ResponseWriterInterface */
    private $responseWriter;

    private $loggingErrorFormatter;

    public function __construct(
        LoggerInterface $logger,
        SchemaFactoryInterface $schemaFactory,
        ErrorCodeProviderInterface $errorCodeProvider,
        RequestReaderInterface $requestReader,
        ResponseWriterInterface $responseWriter
    ) {
        $this->logger = $logger;
        $this->schemaFactory = $schemaFactory;
        $this->errorCodeProvider = $errorCodeProvider;
        $this->requestReader = $requestReader;
        $this->responseWriter = $responseWriter;

        $this->loggingErrorFormatter = function (Error $error) {
            $this->logger->error($error);
            return FormattedError::createFromException($error);
        };
    }

    public function executeGraphQLQuery()
    {
        $httpStatus = null;

        try {
            $queryData = $this->requestReader->getGraphQLRequestData();
            $result = $this->executeQuery($queryData);
        } catch (\Exception $e) {
            $reflectionClass = new \ReflectionClass($e);
            if ($e instanceof HttpErrorInterface) {
                // Thank god. Our own exceptions provide a http status.
                /** @var HttpErrorInterface $e */
                $httpStatus = $e->getHttpStatus();
            }
            $result = $this->createErrorResult($e);
        }
        if (is_null($httpStatus)) {
            $httpStatus = $this->errorCodeProvider->getHttpReturnCode($result);
        }
        $result->setErrorFormatter($this->loggingErrorFormatter);
        $this->responseWriter->renderJsonResponse($result->toArray(), $httpStatus);
    }

    private function createErrorResult(\Exception $e): ExecutionResult
    {
        $msg = $e->getMessage();
        if (! $msg) {
            $msg = 'Unknown error: ' . $e->getTraceAsString();
        }
        $error = new Error($msg);
        $result = new ExecutionResult(null, [$error]);
        return $result;
    }

    /**
     * Execute the GraphQL query
     *
     * @throws \Throwable
     */
    private function executeQuery($queryData)
    {
        $graphQL = new \GraphQL\GraphQL();
        $variables = null;
        if (isset($queryData['variables'])) {
            $variables = (array) $queryData['variables'];
        }
        $operationName = null;
        if (isset($queryData['operationName'])) {
            $operationName = $queryData['operationName'];
        }
        $result = $graphQL->executeQuery(
            $this->schemaFactory->getSchema(),
            $queryData['query'],
            null,
            null,
            $variables,
            $operationName
        );
        return $result;
    }
}
