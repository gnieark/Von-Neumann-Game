(function () {
    function cookieValue(name) {
        return document.cookie
            .split("; ")
            .find((row) => row.startsWith(name + "="))
            ?.split("=")
            .slice(1)
            .join("=") || "";
    }

    function safeDecode(value) {
        try {
            return decodeURIComponent(value || "");
        } catch (error) {
            return value || "";
        }
    }

    function initSwaggerUi() {
        if (!document.getElementById("swagger-ui") || !window.SwaggerUIBundle) {
            return;
        }

        window.SwaggerUIBundle({
            "url": "/openapi.yaml",
            "dom_id": "#swagger-ui",
            "persistAuthorization": true,
            "tryItOutEnabled": true,
            "requestInterceptor": (request) => {
                const sessionToken = safeDecode(cookieValue("vn_session"));
                request.headers = request.headers || {};
                if (sessionToken && !request.headers.Authorization) {
                    request.headers.Authorization = "Bearer " + sessionToken;
                }

                return request;
            },
        });
    }

    document.addEventListener("DOMContentLoaded", initSwaggerUi);
})();
