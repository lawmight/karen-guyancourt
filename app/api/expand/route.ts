import { NextRequest, NextResponse } from "next/server";

const OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions";
const OPENROUTER_MODEL = "openrouter/free";

const systemPrompt = `Vous êtes un assistant qui transforme des signalements informels en lettres formelles en français pour la Mairie de Guyancourt (Yvelines, Île-de-France).

FORMAT DE LA LETTRE :
Commencez TOUJOURS par un bloc de données structurées pour lecture rapide (n'incluez que les champs pour lesquels vous avez des informations) :

---
Résumé : [une ligne résumant le problème, ex: 'Accumulation de déchets sauvages rue de la Mare depuis 3 jours']
Localisation : [adresse/rue mentionnée]
Google Maps : [lien si fourni]
---

Ensuite rédigez la lettre formelle :
- Formule d'appel : 'Madame, Monsieur,'
- Structurer le problème de manière claire et factuelle
- Inclure une demande d'action spécifique
- Terminer avec une formule de politesse formelle

IMPORTANT : N'ajoutez JAMAIS de placeholders, templates ou texte entre crochets comme [nom], [adresse], [Espace pour...], etc. La lettre doit être prête à envoyer sans aucun texte à compléter. Si vous n'avez pas une information, ne l'incluez simplement pas.

Signez toujours la lettre avec :
Veuillez agréer, Madame, Monsieur, l'expression de mes salutations distinguées.`;

type ExpandBody = {
  complaint?: string;
  address?: string;
  lat?: string;
  lng?: string;
};

export async function POST(request: NextRequest) {
  const key =
    request.cookies.get("karen_access")?.value ??
    request.headers.get("x-access-key") ??
    request.nextUrl.searchParams.get("key");
  const expectedKey = process.env.KEY_TO_ACCESS_THE_SCRIPT;
  if (!expectedKey || key !== expectedKey) {
    return NextResponse.json({ success: false, error: "Non autorisé" }, { status: 404 });
  }

  const body = (await request.json()) as ExpandBody;
  const complaint = body.complaint?.trim() ?? "";
  const address = body.address?.trim() ?? "";
  const lat = body.lat?.trim() ?? "";
  const lng = body.lng?.trim() ?? "";

  if (!complaint) {
    return NextResponse.json({ success: false, error: "Le texte du signalement est requis" });
  }

  let userPrompt =
    "Transformez le signalement suivant en une lettre formelle adressée à la Mairie de Guyancourt.\n\n";

  if (address) {
    userPrompt += `Localisation du problème : ${address}\n`;
    if (lat && lng) {
      userPrompt += `Google Maps : https://www.google.com/maps/@${lat},${lng},100m/data=!3m1!1e3\n`;
    }
    userPrompt += "\n";
  }

  userPrompt += `Signalement :\n${complaint}`;

  userPrompt +=
    "\n\nRédigez d'abord la lettre en français, puis écrivez ===ENGLISH=== et la traduction en anglais.";

  const apiKey = process.env.OPENROUTER_API_KEY;
  if (!apiKey) {
    return NextResponse.json(
      { success: false, error: "Configuration OpenRouter manquante" },
      { status: 500 }
    );
  }

  const yourName = (process.env.YOUR_NAME ?? "").trim();
  const response = await fetch(OPENROUTER_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${apiKey}`,
    },
    body: JSON.stringify({
      model: OPENROUTER_MODEL,
      messages: [
        {
          role: "system",
          content: `${systemPrompt}${yourName ? `\n${yourName}` : ""}`,
        },
        {
          role: "user",
          content: userPrompt,
        },
      ],
      max_tokens: 2000,
      temperature: 0.7,
    }),
  });

  if (!response.ok) {
    return NextResponse.json(
      { success: false, error: "Impossible de se connecter à l'API OpenRouter" },
      { status: 502 }
    );
  }

  const data = (await response.json()) as {
    choices?: Array<{ message?: { content?: string | Array<{ type?: string; text?: string }> } }>;
  };
  const rawContent = data.choices?.[0]?.message?.content;
  const expanded = (typeof rawContent === "string"
    ? rawContent
    : Array.isArray(rawContent)
      ? rawContent
          .map((part) => (typeof part === "object" && part && "text" in part ? part.text : String(part)))
          .filter(Boolean)
          .join(" ")
      : ""
  ).trim();

  if (!expanded) {
    return NextResponse.json({ success: false, error: "Réponse invalide de l'API OpenRouter" });
  }

  return NextResponse.json({ success: true, expanded });
}
