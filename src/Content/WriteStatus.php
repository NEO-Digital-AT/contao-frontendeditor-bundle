<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Content;

enum WriteStatus
{
    case Saved;
    case NotFound;
    case Forbidden;
    case Invalid;
}
