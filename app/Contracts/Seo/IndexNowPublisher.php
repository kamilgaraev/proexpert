<?php

declare(strict_types=1);

namespace App\Contracts\Seo;

interface IndexNowPublisher
{
    /**
     * @param  array<int, string>  $urls
     */
    public function publish(array $urls): void;
}
