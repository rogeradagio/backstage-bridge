<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\PayloadSchema;

interface PayloadSchemaInterface
{
    /**
     * @return string[] errors; buit si el payload és vàlid
     */
    public function validate(array $payload): array;
}
