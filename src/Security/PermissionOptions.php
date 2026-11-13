<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Security;

use Contao\DataContainer;
use Contao\StringUtil;
use Neo\FrontendEditorBundle\License\Editions;

/**
 * Options of the field nfe_permissions in tl_user and tl_user_group: the
 * switches of the editions the licence includes (Editions::forBackend). The
 * switches of other editions are not shown; a ticked one keeps its state
 * when the form is saved and is shown again with a licence that includes it.
 */
final class PermissionOptions
{
    public function __construct(private readonly Editions $editions)
    {
    }

    /** @return list<string> */
    public function __invoke(): array
    {
        $edition = $this->editions->forBackend();
        $options = [];

        foreach (Permission::cases() as $permission) {
            if (Editions::includes($edition, $permission->edition())) {
                $options[] = $permission->value;
            }
        }

        return $options;
    }

    /** save_callback: the stored switches that are not shown are kept. */
    public function keepHidden(mixed $value, DataContainer $dc): mixed
    {
        $stored = array_map('strval', StringUtil::deserialize($dc->getCurrentRecord()[Permission::FIELD] ?? null, true));
        $hidden = array_values(array_diff($stored, $this()));

        if ([] === $hidden) {
            return $value;
        }

        return serialize(array_values(array_unique([...array_map('strval', StringUtil::deserialize($value, true)), ...$hidden])));
    }
}
