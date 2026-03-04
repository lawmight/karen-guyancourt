import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import CouncilClient from "./CouncilClient";

const COOKIE_NAME = "karen_access";

export default async function CouncilPage() {
  const cookieStore = await cookies();
  const keyFromCookie = cookieStore.get(COOKIE_NAME)?.value ?? "";
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT ?? "";

  if (!expectedKey || keyFromCookie !== expectedKey) {
    redirect("/login");
  }
  return <CouncilClient accessKey={keyFromCookie} />;
}
