import { NextRequest, NextResponse } from "next/server";

const COOKIE_NAME = "karen_access";

export function middleware(request: NextRequest) {
  if (request.nextUrl.pathname !== "/" || request.method !== "GET") {
    return NextResponse.next();
  }

  const key = request.nextUrl.searchParams.get("key");
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT;

  if (expectedKey && key === expectedKey) {
    const res = NextResponse.redirect(new URL("/", request.url));
    res.cookies.set(COOKIE_NAME, key, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 365, // 1 year
    });
    return res;
  }

  return NextResponse.next();
}
