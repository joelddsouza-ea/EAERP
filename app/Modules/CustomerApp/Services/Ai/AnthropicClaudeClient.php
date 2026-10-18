<?php

namespace App\Modules\CustomerApp\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Claude through the official Anthropic PHP SDK: JSON-schema answers, the cached system prompt,
 * medium effort, and the server's default refusal fallback.
 */
class AnthropicClaudeClient implements ClaudeClient
{
    public function __construct(protected string $apiKey) {}

    public function json(string $model, array $system, array $content, array $schema, int $maxTokens = 4000): array
    {
        $empty = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

        try {
            $client = new Client(apiKey: $this->apiKey);
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $content]],
                model: $model,
                system: $system,
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIStatusException $e) {
            return ['data' => null, 'stop_reason' => null, 'usage' => $empty, 'model' => $model, 'error' => 'AI service: ' . ($e->type?->value ?? 'error') . ' (' . mb_strimwidth($e->getMessage(), 0, 160, '…') . ')'];
        } catch (APIConnectionException $e) {
            return ['data' => null, 'stop_reason' => null, 'usage' => $empty, 'model' => $model, 'error' => 'Could not reach the AI service.'];
        }

        $usage = [
            'input' => (int) $message->usage->inputTokens,
            'output' => (int) $message->usage->outputTokens,
            'cache_read' => (int) ($message->usage->cacheReadInputTokens ?? 0),
            'cache_write' => (int) ($message->usage->cacheCreationInputTokens ?? 0),
        ];

        if ($message->stopReason === 'refusal') {
            return ['data' => null, 'stop_reason' => 'refusal', 'usage' => $usage, 'model' => $message->model, 'error' => null];
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);

        return [
            'data' => is_array($data) ? $data : null,
            'stop_reason' => $message->stopReason,
            'usage' => $usage,
            'model' => $message->model,
            'error' => is_array($data) ? null : 'The AI answer could not be read.',
        ];
    }
}
