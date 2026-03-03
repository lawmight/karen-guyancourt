import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Pin project root so Next doesn't infer a parent folder when multiple lockfiles exist
  turbopack: { root: "." },
};

export default nextConfig;
