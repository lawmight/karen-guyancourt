import { NextRequest, NextResponse } from "next/server";
import sharp from "sharp";

const POSTMARK_URL = "https://api.postmarkapp.com/email";
const MAX_ATTACHMENT_SIZE = 1024 * 1024; // 1 MB

function extractSubject(complaint: string): string {
  const summaryLine = complaint
    .split(/\r?\n/)
    .map((line) => line.trim())
    .find((line) => line.toLocaleLowerCase("fr-FR").startsWith("résumé"));
  if (summaryLine) {
    const colonIndex = summaryLine.indexOf(":");
    if (colonIndex >= 0) {
      const subject = summaryLine.slice(colonIndex + 1).trim();
      if (subject) return subject;
    }
  }
  const trimmed = complaint.replace(/\s+/g, " ").trim();
  return trimmed.length > 60 ? trimmed.slice(0, 60) + "..." : trimmed;
}

async function resizeImage(buffer: Buffer, mimeType: string): Promise<{ data: Buffer; mime: string }> {
  try {
    const img = sharp(buffer);
    const meta = await img.metadata();
    const format = meta.format;

    if (format === "jpeg" || format === "jpg" || format === "png" || format === "webp" || format === "gif") {
      let data = await img.jpeg({ quality: 85 }).toBuffer();
      let quality = 85;
      while (data.length > MAX_ATTACHMENT_SIZE && quality > 20) {
        quality -= 10;
        data = await sharp(buffer).jpeg({ quality }).toBuffer();
      }
      if (data.length > MAX_ATTACHMENT_SIZE) {
        const scale = Math.sqrt(MAX_ATTACHMENT_SIZE / data.length);
        const w = meta.width ?? 800;
        const h = meta.height ?? 600;
        data = await sharp(buffer)
          .resize(Math.round(w * scale), Math.round(h * scale))
          .jpeg({ quality: 70 })
          .toBuffer();
      }
      return { data, mime: "image/jpeg" };
    }
  } catch {
    // fallback
  }
  return { data: buffer, mime: mimeType };
}

export async function POST(request: NextRequest) {
  const key =
    request.cookies.get("karen_access")?.value ??
    request.headers.get("x-access-key") ??
    request.nextUrl.searchParams.get("key");
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT;
  if (!expectedKey || key !== expectedKey) {
    return NextResponse.json({ success: false, message: "Non autorisé" }, { status: 404 });
  }

  const formData = await request.formData();
  const complaint = (formData.get("complaint") as string)?.trim() ?? "";
  const lat = (formData.get("lat") as string)?.trim() ?? "";
  const lng = (formData.get("lng") as string)?.trim() ?? "";

  if (!complaint) {
    return NextResponse.json({
      success: false,
      message: "Veuillez remplir le signalement.",
    });
  }

  const subject = extractSubject(complaint);
  const emailBody = complaint + "\n\nEnvoyé depuis mon iPhone";

  const attachments: { Name: string; Content: string; ContentType: string }[] = [];
  const files = formData.getAll("attachments");
  for (const file of files) {
    if (!(file instanceof File)) continue;
    const buffer = Buffer.from(await file.arrayBuffer());
    const mimeType = file.type || "application/octet-stream";
    let content: Buffer;
    let contentType = mimeType;
    let name = file.name;

    if (mimeType.startsWith("image/") && buffer.length > MAX_ATTACHMENT_SIZE) {
      const resized = await resizeImage(buffer, mimeType);
      content = resized.data;
      contentType = resized.mime;
      if (contentType !== mimeType) {
        name = name.replace(/\.[^.]+$/, ".jpg");
      }
    } else {
      content = buffer;
    }

    attachments.push({
      Name: name,
      Content: content.toString("base64"),
      ContentType: contentType,
    });
  }

  const postmarkToken = process.env.POSTMARK_API_KEY;
  const fromEmail = process.env.FROM_YOUR_EMAIL;
  const toEmail = process.env.TO_COUNCIL_EMAIL;
  const ccEmails = process.env.CC_EMAILS ?? "";

  if (!postmarkToken || !fromEmail || !toEmail) {
    return NextResponse.json(
      { success: false, message: "Configuration email manquante" },
      { status: 500 }
    );
  }

  const payload: {
    From: string;
    To: string;
    Cc?: string;
    Subject: string;
    TextBody: string;
    MessageStream: string;
    Attachments?: typeof attachments;
  } = {
    From: fromEmail,
    To: toEmail,
    Subject: subject,
    TextBody: emailBody,
    MessageStream: "outbound",
  };
  if (ccEmails.trim()) payload.Cc = ccEmails.trim();
  if (attachments.length) payload.Attachments = attachments;

  const res = await fetch(POSTMARK_URL, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Postmark-Server-Token": postmarkToken,
    },
    body: JSON.stringify(payload),
  });

  const responseData = await res.json();

  if (res.ok && responseData.MessageID) {
    const n = attachments.length;
    const msg =
      n > 0
        ? `Signalement envoyé avec succès ! (${n} photo${n !== 1 ? "s" : ""} en pièce jointe)`
        : "Signalement envoyé avec succès !";
    return NextResponse.json({ success: true, message: msg });
  }
  const errorMsg = responseData.Message ?? "Erreur inconnue";
  return NextResponse.json({
    success: false,
    message: "Erreur d'envoi : " + errorMsg,
  });
}
