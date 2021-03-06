<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisterController;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Mail;
use App\Mail\PasswordReset;
use function MongoDB\BSON\toJSON;

class UserController extends Controller
{
    public function __construct()
    {
       // $this->middleware('auth:api');
    }
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'fName' => ['required','regex:/^[a-zA-Z ]+$/', 'string', 'min:8','max:255','regex:/^[\w-]*$/'],
            'lName' => ['required', 'string', 'min:8', 'max:255','regex:/^[\w-]*$/'],
            'CNIC' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'max:13', 'unique:users'],
            'contact' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'max:11', 'unique:users'],
            'address' => [ 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'api_token'=>['string','max:255'],
        ]);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function ucreate(Request $request)
    {
       // return $request;
        $data = Validator::make($request->all(),
            [
            'fName' => ['required','regex:/^[a-zA-Z ]+$/', 'string', 'min:8','max:255','regex:/^[\w-]*$/'],
            'lName' => ['required', 'string', 'min:8', 'max:255','regex:/^[\w-]*$/'],
            'CNIC' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'max:13', 'min:13', 'unique:users'],
            'contact' => ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/', 'max:11', 'min:11', 'unique:users'],
            'address' => [ 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'api_token'=>['string','max:255'],
            ]
        );
        if($data->fails())
             return response()->json(['success'=>false,'message'=>$data->messages()->all()],400);
        $data=$request->all();
        return response()->json(
            ['success'=>true,User::create(
            [
            'fName' => $data['fName'],
            'lName' => $data['lName'],
            'address' => $data['address']??'',
            'date_of_birth' => $data['date_of_birth'],
            'contact' => $data['contact'],
            'CNIC' => $data['CNIC'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => Str::random(60),
        ]
        )
        ]
        );
    }

    public function register(Request $request){
        $resp= $this->ucreate($request);

        if(!$resp->isSuccessful())
        {
            return $resp;
        }
        //return $resp;
        return $this->login($request);
    }
    public function login(Request $request)
    {
        $input = $request->only('email', 'password');
        $jwt_token = null;
        if (!$jwt_token = JWTAuth::attempt($input)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Email or Password',
            ], 401);
        }
        // get the user
          $user = Auth::user();
        $data=['user_id' => Auth::user()->id,
            'token'=>$jwt_token,];
        //ApiToken::create($data);
        return response()->json([
            'success' => true,
            'token' => $jwt_token,
            'user' => $user
        ]);
    }
    public function logout(Request $request)
    {
        if(!User::checkToken($request)){
            return response()->json([
                'message' => 'Token is required',
                'success' => false,
            ],422);
        }
        try {
            JWTAuth::invalidate(JWTAuth::parseToken($request->token));
            return response()->json([
                'success' => true,
                'message' => 'User logged out successfully'
            ]);
        } catch (JWTException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Sorry, the user cannot be logged out',
                'exp'=>$exception->getMessage()
            ], 500);
        }
    }
    public function getPrivileges(Request $request)
    {
        if(!User::checkToken($request)){
            return response()->json([
                'message' => 'Token is required',
                'success' => false,
            ],422);
        }
        $resp=$this->getCurrentUser( $request);
        //$user=json_decode($this->getCurrentUser( $request)->content(), true);
        if($resp->getStatusCode()!=200)
        {
            return $resp;
        }
        $user=json_decode($resp->content(), true);
        $data=User::find($user['user']['id'])->role->rolePrivileges;
        $res=array();
        foreach($data as $x)
        {
            array_push($res,$x->privilege->privilege_name);
        }
        //$data=User::find(1);
        return ['privileges'=>$res];
    }
    public function getCurrentUser(Request $request){
        if(!User::checkToken($request)){
            return response()->json([
                'message' => 'Token is required'
            ],422);
        }
        //$user = JWTAuth::parseToken()->authenticate();
        $user =null;
        try {
            $user =JWTAuth::parseToken()->authenticate();
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            //400
            return response()->json(['success' => false,
                'message' => 'Token Expired'
            ],403 );
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json(['success' => false,
                'message' => 'Token Invalid'
            ],400);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ],500  );
        }
        // add isProfileUpdated....
        $isProfileUpdated=false;
        if($user->isPicUpdated && $user->isEmailUpdated){
            $isProfileUpdated=true;

        }
        $user->isProfileUpdated=$isProfileUpdated;
        return response()->json([
                'success'=>true,
            'user'=>$user,
                ]);
    }
    public function update(Request $request){
        $user=$this->getCurrentUser($request);
        if(!$user){
            return response()->json([
                'success' => false,
                'message' => 'User is not found'
            ]);
        }
        $data=$request;
        unset($data['token']);

        $updatedUser = User::where('id', $user->id)->update($data);
        $user =  User::find($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Information has been updated successfully!',
            'user' =>$user
        ]);
    }
}
