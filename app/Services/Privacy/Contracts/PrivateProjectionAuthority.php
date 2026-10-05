<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

interface PrivateProjectionAuthority
{
    public function authenticate(): ?AuthenticatedPrivateContext;

    public function requiresProject(AuthenticatedPrivateContext $context): bool;

    public function sources(AuthenticatedPrivateContext $context, ProjectionInput $input): array;

    public function allowsSelection(
        AuthenticatedPrivateContext $context,
        ProjectionInput $input,
        PrivateSourceVersion $source,
    ): bool;

    public function allowsField(
        AuthenticatedPrivateContext $context,
        PrivateSourceVersion $source,
        PrivateField $field,
    ): bool;

    public function fields(AuthenticatedPrivateContext $context, PrivateSourceVersion $source): array;

    public function currentSource(
        AuthenticatedPrivateContext $context,
        PrivateSourceVersion $source,
    ): ?PrivateSourceVersion;
}
