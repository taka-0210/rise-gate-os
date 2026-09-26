<?php
namespace App\Http\Controllers;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class PushSubscriptionController extends Controller
{
    private const ALLOWED_HOST_SUFFIXES=['googleapis.com','push.services.mozilla.com','push.apple.com'];
    public function store(Request $request): JsonResponse
    {
        $data=$request->validate(['endpoint'=>['required','url','max:3000'],'keys.p256dh'=>['required','string','max:500'],'keys.auth'=>['required','string','max:500']]);
        $host=strtolower((string)parse_url($data['endpoint'],PHP_URL_HOST));
        $allowed=collect(self::ALLOWED_HOST_SUFFIXES)->contains(fn($suffix)=>$host===$suffix||str_ends_with($host,'.'.$suffix));
        if(!str_starts_with($data['endpoint'],'https://')||!$allowed)throw ValidationException::withMessages(['endpoint'=>'対応するWeb Push endpointではありません。']);
        $company=$request->attributes->get('currentCompany');
        PushSubscription::query()->updateOrCreate(['endpoint_hash'=>hash('sha256',$data['endpoint'])],[
            'organization_id'=>$company->id,'user_id'=>$request->user()->id,'endpoint_encrypted'=>$data['endpoint'],
            'p256dh_encrypted'=>$data['keys']['p256dh'],'auth_encrypted'=>$data['keys']['auth'],'revoked_at_utc'=>null]);
        return response()->json(['saved'=>true],201);
    }
    public function destroy(Request $request): JsonResponse
    {
        $data=$request->validate(['endpoint'=>['required','url','max:3000']]);
        PushSubscription::query()->where('organization_id',$request->attributes->get('currentCompany')->id)->where('user_id',$request->user()->id)
            ->where('endpoint_hash',hash('sha256',$data['endpoint']))->update(['revoked_at_utc'=>now('UTC')]);
        return response()->json(['revoked'=>true]);
    }
}
