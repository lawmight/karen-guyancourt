"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";

const COOKIE_NAME = "karen_access";

export async function loginWithKey(formData: FormData) {
  const submittedKey = String(formData.get("key") ?? "");
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT ?? "";

  if (!expectedKey || submittedKey !== expectedKey) {
    redirect("/login?error=1");
  }

  const cookieStore = await cookies();
  cookieStore.set(COOKIE_NAME, submittedKey, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: 60 * 60 * 24 * 365,
  });

  redirect("/");
}
