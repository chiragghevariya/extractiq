<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('company.{companyId}', fn ($user, int $companyId) => (int) $user->company_id === $companyId);
