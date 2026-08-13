<?php

namespace App\Support;

final class FormFcPeriod
{
    /**
     * Temporary Form FC trial period used by every Forecast page and save path.
     *
     * Legacy database columns such as avg6, base_avg6, history_avg6,
     * forecast_6m, division_forecast_6m, and approval_forecast_6m keep their
     * existing names during the trial, but contain 4-month values.
     */
    public const MONTHS = 4;
}
