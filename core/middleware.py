class PrivateResponseMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        response = self.get_response(request)
        if request.user.is_authenticated or request.path.startswith(
            ("/respond/", "/login/", "/password/", "/receipt/")
        ):
            response["Cache-Control"] = "no-store, private"
        response["Referrer-Policy"] = "same-origin"
        if request.path.startswith("/respond/"):
            response["Referrer-Policy"] = "no-referrer"
        response["Content-Security-Policy"] = (
            "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
        )
        return response
