<?php

namespace App\Modules\CustomerApp\Services\Ai;

/**
 * One call to Claude that returns JSON matching a schema. Behind an interface so tests (and
 * a switched-off assistant) never reach the paid API.
 */
interface ClaudeClient
{
    /**
     * @param array<int, array<string, mixed>> $system system text blocks (the first ones cached)
     * @param array<int, array<string, mixed>> $content the user's content blocks (text, images)
     * @param array<string, mixed> $schema JSON schema of the answer
     * @return array{data: ?array, stop_reason: ?string, usage: array{input: int, output: int, cache_read: int, cache_write: int}, model: string, error: ?string}
     */
    public function json(string $model, array $system, array $content, array $schema, int $maxTokens = 4000): array;
}
