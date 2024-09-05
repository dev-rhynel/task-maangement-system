<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyUserRequest;
use App\Http\Requests\Auth\RequestResetPassword;
use App\Http\Requests\Auth\OnlyTokenRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Core\ApiResponse;
use App\Http\Controllers\Controller;
use App\Repositories\RepoService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Events\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class AuthenticationController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @param RepoService $repoService
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RepoService $repoService, RegisterRequest $request): JsonResponse
    {
        $registrationData = $request->only([
            'first_name',
            'last_name',
            'email',
        ]);

        $user = $repoService->user()->create([
            'username' => $repoService->user()->setUsernameAttribute(
                $registrationData['first_name'],
                $registrationData['last_name']
            ),
            'password' => Hash::make($request->get('password')),
            'email_verification_token' => Str::random(60),
            'latest_key' => Str::random(12),
            'email' => Str::lower($registrationData['email']),
            ...$registrationData
        ]);

        $token = $user->createToken('grant-token:user')->accessToken;

        return ApiResponse::success(['token' => $token], 'Successfully registered!', 201);
    }


    /**
     * Handle an incoming login request.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function authenticate(LoginRequest $request): JsonResponse
    {
        $user = $request->handleAuthentication();

        $token = $user->createToken('grant-token:user')->accessToken;

        return ApiResponse::success(
            [
                'token' => $token,
            ],
            message:'Successfully logged in!',
        );
    }

    /**
     * Verify a user's email address.
     *
     * @param VerifyUserRequest $request
     * @return JsonResponse
     */
    public function verify(VerifyUserRequest $request): JsonResponse
    {
        $verificationToken = $request->get('token');

        $user = RepoService::user()->update(
            ['email_verification_token' => $verificationToken],
            [
                'email_verified_at' => now(),
                'email_verification_token' => null
            ]
        );

        $token = $user->createToken('grant-token:user')
                    ->accessToken;

        return ApiResponse::success(['token' => $token], 'Successfully verified!', 201);
    }


    /**
     * Send a password reset link to the specified email address.
     *
     * @param RequestResetPassword $request
     * @return JsonResponse
     */
    public function requestPasswordResetLink(RequestResetPassword $request): JsonResponse
    {
        $email = $request->get('email');
        $user = RepoService::user()->checkIfEmailExists($email);

        if ($user) {
            $token = $this->createPasswordResetToken($email);
            ResetPassword::dispatch($user, $token);
        }

        return ApiResponse::success(
            message: 'Password reset link sent to your email!'
        );
    }


    /**
     * Create a new password reset token for the given email address.
     *
     * @param  string  $email
     * @return string
     */
    private function createPasswordResetToken(string $email): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')
            ->updateOrInsert(
                ['email' => $email],
                [
                    'token' => $token,
                    'created_at' => now(),
                ]
            );

        return $token;
    }


    /**
     * Retrieve a password reset by token.
     *
     * @param  string  $token
     * @return \stdClass|null
     */
    private function getPasswordResetByToken(string $token): ?\stdClass
    {
        return DB::table('password_reset_tokens')
            ->where('token', $token)
            ->first();
    }


    /**
     * Validate a password reset token
     *
     * @param string $resetToken The password reset token
     *
     * @return bool|string true if the token is valid, 'Token is expired!' if the token is expired, false if the token does not exist
     */
    private function validatePasswordResetToken(string $resetToken): bool|string
    {
        $resetTokenExists = $this->getPasswordResetByToken($resetToken);

        $resetTokenIsActive = DB::table('password_reset_tokens')
            ->where('token', $resetToken)
            ->where('created_at', '>', now()->subHour())
            ->first();

        if ($resetTokenExists instanceof \stdClass) {
            return $resetTokenIsActive instanceof \stdClass ? true : 'Token is expired!';
        }

        return false;
    }


    public function validatePassword(OnlyTokenRequest $request): JsonResponse
    {
        $validationResult = $this->validatePasswordResetToken($request->get('token'));

        if ($validationResult === true) {
            return ApiResponse::success(message: 'Token is valid.');
        }

        $errorMessage = match ($validationResult) {
            'Token is expired!' => 'Token is expired.',
            default => 'Token is invalid!',
        };

        return ApiResponse::error($errorMessage, 401);
    }


    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        $passwordResetToken = $this->getPasswordResetByToken($request->get('token'));

        if (!$passwordResetToken) {
            return ApiResponse::error('Token is invalid!', 401);
        }

        $user = RepoService::user()->update(
            ['email' => $passwordResetToken->email],
            [
                'password' => Hash::make($request->get('password')),
            ]
        );

        DB::table('password_reset_tokens')
            ->where('email', $passwordResetToken->email)
            ->delete();

        return ApiResponse::success(message: 'Password successfully changed!');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();

        return ApiResponse::success(message: 'Successfully logged out!');
    }
}
