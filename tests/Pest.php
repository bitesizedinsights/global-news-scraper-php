<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function loadFixture(string $name): array
{
    $json = file_get_contents(__DIR__."/fixtures/{$name}.json");
    assert(is_string($json));

    /** @var array<string, mixed> */
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}
