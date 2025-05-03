<?php

namespace App\DTOs\Auth;

class LoginDTO
{
    private string $email;
    private string $password;

    public function __construct(string $email, string $password)
    {
        $this->email = $email;
        $this->password = $password;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
    
    /**
     * Магический метод для доступа к приватным свойствам.
     *
     * @param string $name
     * @return mixed
     * @throws \Exception
     */
    public function __get(string $name)
    {
        $method = 'get' . ucfirst($name);
        
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        $property = lcfirst(preg_replace('/^get/', '', $method));
        
        if (property_exists($this, $property)) {
            return $this->$property;
        }
        
        throw new \Exception("Свойство {$name} не существует в " . get_class($this));
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }

    public static function fromRequest(array $data): self
    {
        return new self(
            $data['email'],
            $data['password']
        );
    }
} 