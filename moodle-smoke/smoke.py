"""Signs in to Moodle through the OpenID provider like a browser would.

Each run uses fresh cookie jars for Moodle and the provider, so the second
run is a completely new sign-in. Prints the Moodle user it ends up as.
"""

import html
import os
import re
import sys
from urllib.parse import urljoin

import requests

MOODLE = os.environ["MOODLE_URL"].rstrip("/")
EMAIL = os.environ["USER_EMAIL"]
PASSWORD = os.environ["USER_PASSWORD"]


def fail(message, response=None):
    print("FAIL:", message)
    if response is not None:
        print("  at", response.url, response.status_code)
        print(response.text[:1500])
    sys.exit(1)


def form_field(page, name):
    match = re.search(r'name="%s"\s+value="([^"]*)"' % re.escape(name), page)
    return html.unescape(match.group(1)) if match else None


def sign_in():
    session = requests.Session()

    login_page = session.get(MOODLE + "/login/index.php")
    match = re.search(r'href="([^"]*auth/oauth2/login\.php[^"]*)"', login_page.text)
    if not match:
        fail("Moodle login page has no OAuth 2 button", login_page)

    # Moodle -> provider /oauth/authorize -> provider /login
    response = session.get(html.unescape(match.group(1)))
    if "/login" not in response.url or "password" not in response.text:
        fail("expected the provider's login form", response)

    token = form_field(response.text, "_token")
    response = session.post(
        urljoin(response.url, "/login"),
        data={"_token": token, "email": EMAIL, "password": PASSWORD},
    )

    # A consent screen appears unless consent is skipped for the client.
    if 'name="auth_token"' in response.text:
        response = session.post(
            urljoin(response.url, "/oauth/authorize"),
            data={
                "_token": form_field(response.text, "_token"),
                "auth_token": form_field(response.text, "auth_token"),
            },
        )

    if not response.url.startswith(MOODLE):
        fail("expected to land back on Moodle", response)

    profile = session.get(MOODLE + "/user/profile.php")
    if "/login/" in profile.url:
        fail("Moodle did not create a session", profile)

    name = re.search(r"<h1[^>]*>([^<]+)</h1>", profile.text)
    print("signed in as:", name.group(1).strip() if name else "(unknown)")


if __name__ == "__main__":
    sign_in()
