<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

/** What a programme leads to. */
enum Award: string
{
    case Certificate = 'certificate';
    case Diploma = 'diploma';
    case Bachelor = 'bachelor';
    case PostgraduateDiploma = 'postgraduate_diploma';
    case Master = 'master';
    case Doctorate = 'doctorate';
}
