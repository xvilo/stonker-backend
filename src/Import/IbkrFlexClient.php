<?php

declare(strict_types=1);

namespace App\Import;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the IBKR Flex Web Service (read-only, token-based — no gateway).
 *
 * Two steps: request the statement (returns a reference code), then download it.
 * Returns a FlexFetchResult so callers can distinguish a real statement from a
 * transient failure (throttle 1001, still-generating 1019, transport error).
 */
final class IbkrFlexClient
{
    private const SEND_URL = 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService.SendRequest';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function fetchStatement(string $token, string $queryId): FlexFetchResult
    {
        try {
            $send = $this->httpClient->request('GET', self::SEND_URL, [
                'query' => ['t' => $token, 'q' => $queryId, 'v' => 3],
                'timeout' => 30,
            ])->getContent(false);

            $xml = @simplexml_load_string($send);
            if (false === $xml) {
                return new FlexFetchResult(errorMessage: 'unparseable SendRequest response');
            }

            if ('Success' !== (string) ($xml->Status ?? '')) {
                $result = new FlexFetchResult(
                    errorCode: (string) ($xml->ErrorCode ?? '') ?: null,
                    errorMessage: (string) ($xml->ErrorMessage ?? '') ?: 'SendRequest did not succeed',
                );
                $this->logger?->warning('IBKR Flex SendRequest failed', ['code' => $result->errorCode, 'message' => $result->errorMessage]);

                return $result;
            }

            $referenceCode = (string) $xml->ReferenceCode;
            $statementUrl = (string) $xml->Url;

            $statement = $this->httpClient->request('GET', $statementUrl, [
                'query' => ['t' => $token, 'q' => $referenceCode, 'v' => 3],
                'timeout' => 60,
            ])->getContent(false);

            if (str_contains($statement, 'Statement generation in progress')) {
                return new FlexFetchResult(errorCode: '1019', errorMessage: 'Statement still generating; retry shortly.');
            }

            // A successful statement is a <FlexQueryResponse>. A second-step error
            // comes back as a <FlexStatementResponse> with a non-Success Status.
            $stmtXml = @simplexml_load_string($statement);
            if (false !== $stmtXml
                && 'FlexStatementResponse' === $stmtXml->getName()
                && 'Success' !== (string) ($stmtXml->Status ?? '')) {
                return new FlexFetchResult(
                    errorCode: (string) ($stmtXml->ErrorCode ?? '') ?: null,
                    errorMessage: (string) ($stmtXml->ErrorMessage ?? 'statement fetch failed'),
                );
            }

            return new FlexFetchResult(statement: $statement);
        } catch (\Throwable $e) {
            $this->logger?->error('IBKR Flex fetch failed', ['error' => $e->getMessage()]);

            return new FlexFetchResult(errorMessage: $e->getMessage());
        }
    }
}
