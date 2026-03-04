import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import CouncilClient from "./CouncilClient";

const COOKIE_NAME = "karen_access";

export default async function CouncilPage({
  searchParams,
}: {
  searchParams: Promise<{ key?: string }>;
}) {
  const params = await searchParams;
  const cookieStore = await cookies();
  const keyFromCookie = cookieStore.get(COOKIE_NAME)?.value ?? "";
  const keyFromQuery = params.key ?? "";
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT ?? "";

  const key = keyFromCookie || keyFromQuery;
  if (!expectedKey || key !== expectedKey) {
    notFound();
  }
  return <CouncilClient accessKey={key} />;
}
