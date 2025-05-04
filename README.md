# ... existing code ...

## API Documentation

The API is divided into three parts: Mobile, Admin, and Landing.
OpenAPI 3.0 (Swagger) documentation for each part is available in the project root:

*   `openapi_mobile.yaml`: Documentation for the Mobile App API (for foremen).
*   `openapi_admin.yaml`: Documentation for the Admin Panel API.
*   `openapi_landing.yaml`: Documentation for the Landing/Main Application API (registration, user profile, etc.).

### Viewing Documentation

You can use various tools to view the documentation interactively:

1.  **Swagger UI / Swagger Editor:** Load the respective `.yaml` file into a local or online instance of Swagger Editor or Swagger UI.
    *   *Example using Docker for Swagger UI:* 
        ```bash
        docker run -p 8080:8080 -e SWAGGER_JSON_URL=https://raw.githubusercontent.com/YOUR_REPO_PATH/openapi_mobile.yaml swaggerapi/swagger-ui
        # Replace URL with the raw path to your file or mount local file
        # Access at http://localhost:8080
        ```
        *(Adjust the command to serve local files if needed)*

2.  **Postman:** Import the `.yaml` files into Postman to explore endpoints and generate requests.

3.  **IDE Plugins:** Many IDEs (like VS Code, PhpStorm/IntelliJ) have plugins that can render OpenAPI files.

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
