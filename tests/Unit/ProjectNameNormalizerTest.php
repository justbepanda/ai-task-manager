<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProjectNameNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectNameNormalizerTest extends TestCase
{
    #[Test]
    public function folds_latin_and_cyrillic_gigaplan_alike(): void
    {
        $this->assertSame('gigaplan', ProjectNameNormalizer::fold('GigaPlan'));
        $this->assertSame('gigaplan', ProjectNameNormalizer::fold('Гигаплан'));
        $this->assertTrue(ProjectNameNormalizer::equals('GigaPlan', 'Гигаплан'));
        $this->assertTrue(ProjectNameNormalizer::matches('GigaPlan', 'Гигаплан'));
    }

    #[Test]
    public function folds_tekta_variants(): void
    {
        $this->assertTrue(ProjectNameNormalizer::equals('Tekta', 'Текта'));
    }
}
