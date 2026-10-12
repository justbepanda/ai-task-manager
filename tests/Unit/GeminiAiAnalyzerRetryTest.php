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
            'ai.gemini.api_keys' => ['test-key'],
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
            'ai.gemini.api_keys' => ['test-key'],
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

    public function test_skips_404_model_and_uses_next_fallback(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.api_keys' => ['test-key'],
            'ai.gemini.model' => 'gemini-dead',
            'ai.gemini.fallback_models' => ['gemini-alive'],
            'ai.gemini.retry.max_attempts' => 1,
            'ai.gemini.retry.base_delay_ms' => 0,
            'ai.gemini.thinking_level' => null,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-dead')) {
                return Http::response(['error' => ['message' => 'not found']], 404);
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

    public function test_switches_to_fallback_immediately_on_429(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.api_keys' => ['test-key'],
            'ai.gemini.model' => 'gemini-3.6-flash',
            'ai.gemini.fallback_models' => ['gemini-3.5-flash'],
            'ai.gemini.retry.max_attempts' => 3,
            'ai.gemini.retry.base_delay_ms' => 0,
            'ai.gemini.thinking_level' => null,
        ]);

        $primaryHits = 0;

        Http::fake(function ($request) use (&$primaryHits) {
            if (str_contains($request->url(), 'gemini-3.6-flash')) {
                $primaryHits++;

                return Http::response(['error' => ['message' => 'rate limit']], 429);
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

        $analyzer = new GeminiAiAnalyzer;
        $result = $analyzer->completeJson('sys', 'user');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $primaryHits, '429 should switch to fallback without burning all retries');
        $this->assertSame('gemini-3.5-flash', $analyzer->lastModel());
    }

    public function test_switches_to_next_api_key_on_429(): void
    {
        config([
            'ai.gemini.api_key' => 'key-one',
            'ai.gemini.api_keys' => ['key-one', 'key-two'],
            'ai.gemini.model' => 'gemini-primary',
            'ai.gemini.fallback_models' => ['gemini-fallback'],
            'ai.gemini.retry.max_attempts' => 3,
            'ai.gemini.retry.base_delay_ms' => 0,
            'ai.gemini.thinking_level' => null,
        ]);

        $keyOneHits = 0;
        $keyTwoHits = 0;

        Http::fake(function ($request) use (&$keyOneHits, &$keyTwoHits) {
            $key = $request->header('x-goog-api-key')[0] ?? '';

            if ($key === 'key-one') {
                $keyOneHits++;

                return Http::response(['error' => ['message' => 'rate limit']], 429);
            }

            if ($key === 'key-two') {
                $keyTwoHits++;

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
            }

            return Http::response(['error' => ['message' => 'unexpected key']], 500);
        });

        $result = (new GeminiAiAnalyzer)->completeJson('sys', 'user');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $keyOneHits, '429 should switch key without burning model retries');
        $this->assertSame(1, $keyTwoHits);
    }

    public function test_503_message_is_user_friendly(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.api_keys' => ['test-key'],
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
