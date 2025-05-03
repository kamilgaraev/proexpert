<?php

namespace App\DTOs\Auth;

class RegisterDTO
{
    private string $name;
    private string $email;
    private string $password;
    private ?string $phone;
    private ?string $position;
    
    // Данные организации
    private string $organizationName;
    private ?string $organizationLegalName;
    private ?string $organizationTaxNumber;
    private ?string $organizationRegistrationNumber;
    private ?string $organizationPhone;
    private ?string $organizationEmail;
    private ?string $organizationAddress;
    private ?string $organizationCity;
    private ?string $organizationPostalCode;
    private ?string $organizationCountry;

    public function __construct(
        string $name,
        string $email,
        string $password,
        ?string $phone,
        ?string $position,
        string $organizationName,
        ?string $organizationLegalName,
        ?string $organizationTaxNumber,
        ?string $organizationRegistrationNumber,
        ?string $organizationPhone,
        ?string $organizationEmail,
        ?string $organizationAddress,
        ?string $organizationCity,
        ?string $organizationPostalCode,
        ?string $organizationCountry
    ) {
        $this->name = $name;
        $this->email = $email;
        $this->password = $password;
        $this->phone = $phone;
        $this->position = $position;
        $this->organizationName = $organizationName;
        $this->organizationLegalName = $organizationLegalName;
        $this->organizationTaxNumber = $organizationTaxNumber;
        $this->organizationRegistrationNumber = $organizationRegistrationNumber;
        $this->organizationPhone = $organizationPhone;
        $this->organizationEmail = $organizationEmail;
        $this->organizationAddress = $organizationAddress;
        $this->organizationCity = $organizationCity;
        $this->organizationPostalCode = $organizationPostalCode;
        $this->organizationCountry = $organizationCountry ?? 'Россия';
    }
    
    /**
     * Получение email пользователя.
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }
    
    /**
     * Получение названия организации.
     *
     * @return string
     */
    public function getOrganizationName(): string
    {
        return $this->organizationName;
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

    public function getUserData(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'phone' => $this->phone,
            'position' => $this->position,
        ];
    }

    public function getOrganizationData(): array
    {
        return [
            'name' => $this->organizationName,
            'legal_name' => $this->organizationLegalName,
            'tax_number' => $this->organizationTaxNumber,
            'registration_number' => $this->organizationRegistrationNumber,
            'phone' => $this->organizationPhone,
            'email' => $this->organizationEmail,
            'address' => $this->organizationAddress,
            'city' => $this->organizationCity,
            'postal_code' => $this->organizationPostalCode,
            'country' => $this->organizationCountry,
        ];
    }

    public static function fromRequest(array $data): self
    {
        return new self(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['phone'] ?? null,
            $data['position'] ?? null,
            $data['organization_name'],
            $data['organization_legal_name'] ?? null,
            $data['organization_tax_number'] ?? null,
            $data['organization_registration_number'] ?? null,
            $data['organization_phone'] ?? null,
            $data['organization_email'] ?? null,
            $data['organization_address'] ?? null,
            $data['organization_city'] ?? null,
            $data['organization_postal_code'] ?? null,
            $data['organization_country'] ?? null
        );
    }
} 