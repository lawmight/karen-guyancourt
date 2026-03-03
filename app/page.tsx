import { notFound } from "next/navigation";
import CouncilClient from "./CouncilClient";

export default async function CouncilPage({
  searchParams,
}: {
  searchParams: Promise<{ key?: string }>;
}) {
  const params = await searchParams;
  const key = params.key ?? "";
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT ?? "";
  if (!expectedKey || key !== expectedKey) {
    notFound();
  }
  return <CouncilClient accessKey={key} />;
}
