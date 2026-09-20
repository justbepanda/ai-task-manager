<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\Exceptions\AnalysisException;
use App\AI\GeminiAiAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAiAnalyzerRetryTest extends TestCase
{
    public function test_retries_503_then_succeeds(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.model' => 'gemini-test',
            'ai.gemini.fallback_models' => [],
            'ai.gemini.retry.max_attempts' => 3,
            'ai.gemini.retry.base_delay_ms' => 0,
            'ai.gemini.thinking_level' => 'low',
        ]);

        Http::fake([
            '*models/gemini-test:generateContent*' => Http::sequence()
                ->push(['error' => ['message' => 'unavailable']], 503)
                ->push(['error' => ['message' => 'unavailable']], 503)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => '{"groups":[],"unmatched_message_ids":[]}'],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $result = (new GeminiAiAnalyzer)->completeJson('sys', 'user');

        $this->assertSame([], $result['groups']);
        Http::assertSentCount(3);
    }

    public function test_falls_back_to_next_model_after_retries(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.model' => 'gemini-primary',
            'ai.gemini.fallback_models' => ['gemini-fallback'],
            'ai.gemini.retry.max_attempts' => 1,
            'ai.gemini.retry.base_delay_ms' => 0,
            'ai.gemini.thinking_level' => null,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-primary')) {
                return Http::response(['error' => ['message' => 'unavailable']], 503);
            }

            return Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"ok":true}'],
                            ],
                        ],
                    ],
                ],
            ], 200);
        });

        $result = (new GeminiAiAnalyzer)->completeJson('sys', 'user');

        $this->assertTrue($result['ok']);
    }

    public function test_503_message_is_user_friendly(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.model' => 'gemini-test',
            'ai.gemini.fallback_models' => [],
            'ai.gemini.retry.max_attempts' => 1,
            'ai.gemini.retry.base_delay_ms' => 0,
        ]);

        Http::fake([
            '*' => Http::response(['error' => ['message' => 'unavailable']], 503),
        ]);

        try {
            (new GeminiAiAnalyzer)->completeJson('sys', 'user');
            $this->fail('Expected AnalysisException');
        } catch (AnalysisException $e) {
            $this->assertStringContainsString('временно недоступен', $e->getMessage());
            $this->assertStringContainsString('503', $e->getMessage());
        }
    }
}
