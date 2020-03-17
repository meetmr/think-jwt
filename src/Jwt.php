<?php

declare(strict_types=1);

namespace xiaodi\JWTAuth;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\ValidationData;
use think\App;
use xiaodi\JWTAuth\Exception\JWTException;
use xiaodi\JWTAuth\Exception\JWTInvalidArgumentException;
use xiaodi\JWTAuth\Exception\TokenAlreadyEexpired;
use xiaodi\JWTAuth\Handle\RequestToken;

class Jwt
{
    /**
     * @var User
     */
    private $user;

    /**
     * @var Token
     */
    private $token;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @var Builder
     */
    private $builder;

    use \xiaodi\JWTAuth\Traits\Jwt;

    public function __construct(App $app, Manager $manager, Builder $builder)
    {
        $this->app = $app;
        $this->manager = $manager;
        $this->builder = $builder;

        $config = $this->getConfig();
        foreach ($config as $key => $v) {
            $this->$key = $v;
        }
    }

    /**
     * 获取jwt配置.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->app->config->get('jwt.default', []);
    }

    /**
     * 生成 Token.
     *
     * @param array $claims
     *
     * @return Token
     */
    public function token(array $claims): Token
    {
        $uniqid = $this->makeTokenId();

        $this->builder->setIssuer($this->iss())
            ->setAudience($this->aud())
            ->setId($uniqid, true)
            ->setIssuedAt(time())
            ->setNotBefore(time() + $this->notBefore())
            ->setExpiration(time() + $this->ttl());

        foreach ($claims as $key => $claim) {
            $this->builder->set($key, $claim);
        }

        $token = $this->builder->getToken($this->getSigner(), $this->makeSignerKey());

        $this->manager->login($token);

        return $token;
    }

    /**
     * @return string
     */
    private function makeTokenId(): string
    {
        $uniqid = uniqid();

        return (string) $uniqid;
    }

    /**
     * 获取 当前用户.
     *
     * @return User
     */
    public function user(): User
    {
        return $this->user;
    }

    /**
     * 刷新 Token.
     *
     * @return void
     */
    public function refresh()
    {
        $token = $this->getRequestToken();

        $this->manager->refresh($token);
    }

    /**
     * 自动获取请求下的Token.
     *
     * @return Token
     */
    protected function getRequestToken(): Token
    {
        $requestToken = new RequestToken($this->app);

        $token = $requestToken->getToken($this->type());

        try {
            $token = (new Parser())->parse($token);
        } catch (\InvalidArgumentException $e) {
            throw new JWTInvalidArgumentException('此 Token 解析失败', 500);
        }

        return $token;
    }

    /**
     * 解析 Token.
     *
     * @return Token
     */
    public function parseToken(): Token
    {
        $token = $this->getRequestToken();

        return $token;
    }

    /**
     * 登出.
     *
     * @return void
     */
    public function logout()
    {
        $token = $this->getRequestToken();

        $this->manager->refresh($token);
    }

    /**
     * 验证 Token.
     *
     * @param Token $token
     *
     * @return bool
     */
    public function verify(Token $token = null)
    {
        $this->token = $token ?: $this->getRequestToken();

        try {
            $this->validateToken();

            // 是否已过期
            if ($this->token->isExpired()) {
                if (time() < ($this->token->getClaim('iat') + $this->refreshTTL())) {
                    throw new TokenAlreadyEexpired('Token 已过期，请重新刷新', 401, $this->getAlreadyCode());
                } else {
                    throw new TokenAlreadyEexpired('Token 刷新时间已过，请重新登录', 401, $this->getReloginCode());
                }
            }
        } catch (\BadMethodCallException $e) {
            throw new JWTException('此 Token 未进行签名', 500);
        }

        return true;
    }

    /**
     * 效验 Token.
     *
     * @return void
     */
    protected function validateToken()
    {
        // 验证密钥是否与创建签名的密钥一致
        if (false === $this->token->verify($this->getSigner(), $this->makeKey())) {
            throw new JWTException('此 Token 与 密钥不匹配', 500);
        }

        // 是否可用
        $exp = $this->token->getClaim('nbf');
        if (time() < $exp) {
            throw new JWTException('此 Token 暂未可用', 500);
        }

        $data = new ValidationData();

        $jwt_id = $this->token->getHeader('jti');
        $data->setIssuer($this->iss());
        $data->setAudience($this->aud());
        $data->setId($jwt_id);

        if (!$this->token->validate($data)) {
            throw new JWTException('此 Token 效验不通过', 500);
        }

        if ($this->manager->hasBlacklist($this->token)) {
            throw new JWTException('此 Token 已注销', 500);
        }
    }
}
