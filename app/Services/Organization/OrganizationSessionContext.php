<?php

namespace App\Services\Organization;

use App\Models\OrganizationUser;
use Illuminate\Http\Request;

class OrganizationSessionContext
{
    public const COMPANY_ID = 'current_company_id';

    public const WORKSPACE_ID = 'current_workspace_id';

    public const ACCESS_EPOCH = 'current_company_access_epoch';

    public function select(Request $request, OrganizationUser $membership): void
    {
        $request->session()->put(self::COMPANY_ID, $membership->organization_id);
        $request->session()->put(self::ACCESS_EPOCH, $membership->access_epoch);
        $request->session()->forget(self::WORKSPACE_ID);
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([self::COMPANY_ID, self::WORKSPACE_ID, self::ACCESS_EPOCH]);
    }
}
