import { NextRequest, NextResponse } from "next/server";

const COOKIE_NAME = "karen_access";

export function middleware(request: NextRequest) {
  if (request.method !== "GET") {
    return NextResponse.next();
  }

  const pathname = request.nextUrl.pathname;
  if (pathname !== "/" && pathname !== "/login") {
    return NextResponse.next();
  }

  const key = request.nextUrl.searchParams.get("key");
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT;
  const hasValidCookie = request.cookies.get(COOKIE_NAME)?.value === expectedKey;

  if (expectedKey && key === expectedKey) {
    const redirectUrl = request.nextUrl.clone();
    redirectUrl.searchParams.delete("key");
    if (pathname === "/login") redirectUrl.pathname = "/";
    const res = NextResponse.redirect(redirectUrl);
    res.cookies.set(COOKIE_NAME, key, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 365, // 1 year
    });
    return res;
  }

  if (pathname === "/login") {
    if (hasValidCookie) {
      return NextResponse.redirect(new URL("/", request.url));
    }
    return NextResponse.next();
  }

  if (!hasValidCookie) {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  return NextResponse.next();
}
