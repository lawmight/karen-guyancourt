"use client";

import Script from "next/script";
import { useCallback, useEffect, useRef } from "react";

const MAP_CENTER_LAT = 48.7734;
const MAP_CENTER_LNG = 2.0738;

export default function CouncilClient({ accessKey }: { accessKey: string }) {
  return (
    <div className="root">
      <Script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossOrigin=""
        strategy="afterInteractive"
      />
      <h1>🇫🇷 Signalement à la Mairie de Guyancourt (💁‍♀️ Karen Bot)</h1>

      <form
        id="complaintForm"
        onSubmit={(e) => e.preventDefault()}
        className="form"
      >
        <input type="hidden" id="selectedLat" name="lat" />
        <input type="hidden" id="selectedLng" name="lng" />

        <button type="button" className="btn btn-secondary" id="locateBtn">
          Me localiser
        </button>
        <label>Cliquez sur la carte pour placer le marqueur</label>
        <div id="map-root" className="map" />
        <div className="location-info" id="locationInfo">
          Aucun emplacement sélectionné
        </div>

        <label>Joindre des photos (optionnel)</label>
        <UploadBox />

        <label htmlFor="input">Votre signalement</label>
        <textarea
          id="input"
          required
          placeholder="Décrivez votre problème ici..."
          className="textarea input"
          rows={4}
        />

        <button type="button" className="btn btn-primary" id="expandBtn">
          Rédiger la lettre
        </button>

        <label htmlFor="complaint" style={{ marginTop: 15 }}>
          Lettre formelle en français (celle-ci sera envoyée)
        </label>
        <textarea
          id="complaint"
          name="complaint"
          placeholder="La lettre en français apparaîtra ici..."
          className="textarea complaint"
          rows={12}
        />

        <label htmlFor="expanded" style={{ marginTop: 15 }}>
          Traduction en anglais (pour votre référence)
        </label>
        <textarea
          id="expanded"
          readOnly
          placeholder="La traduction en anglais apparaîtra ici..."
          className="textarea expanded"
          rows={12}
        />

        <div className="buttons">
          <button type="button" className="btn btn-primary" id="sendBtn">
            Envoyer le signalement
          </button>
        </div>

        <div id="statusMessage" style={{ marginTop: 15 }} />
      </form>

      <CouncilScript accessKey={accessKey} />
    </div>
  );
}

function UploadBox() {
  return (
    <div className="upload-box" id="uploadBox">
      <div id="uploadPlaceholder">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width={48} height={48}>
          <path
            fill="currentColor"
            d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"
          />
        </svg>
        <p>Cliquez pour ajouter des photos ou glissez-déposez</p>
      </div>
      <div id="imagePreview" className="image-preview" />
      <input
        type="file"
        id="attachments"
        name="attachments"
        multiple
        accept="image/*"
        className="file-input"
        aria-label="Joindre des photos"
      />
    </div>
  );
}

function CouncilScript({ accessKey }: { accessKey: string }) {
  const initRef = useRef(false);
  type MapLike = {
    setView: (coords: [number, number], zoom: number) => MapLike;
    on: (event: string, handler: (e: { latlng: { lat: number; lng: number } }) => void) => void;
  };
  type MarkerLike = {
    addTo: (map: MapLike) => MarkerLike;
    setLatLng: (latlng: { lat: number; lng: number }) => void;
  };
  type LeafletLike = {
    map: (id: string) => MapLike;
    tileLayer: (url: string, options: { attribution: string }) => { addTo: (map: MapLike) => void };
    control: { layers: (layers: Record<string, unknown>) => { addTo: (map: MapLike) => void } };
    marker: (latlng: { lat: number; lng: number }) => MarkerLike;
  };

  const runScript = useCallback(() => {
    if (initRef.current) return;
    initRef.current = true;

    const mapEl = document.getElementById("map-root");
    if (!mapEl) return;

    const L = (window as unknown as { L: LeafletLike }).L;
    if (!L) return;

    const map = L.map("map-root").setView([MAP_CENTER_LAT, MAP_CENTER_LNG], 13);
    const streets = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "© OpenStreetMap",
    });
    const satellite = L.tileLayer(
      "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
      { attribution: "© Esri" }
    );
    satellite.addTo(map);
    L.control.layers({ Plan: streets, Satellite: satellite }).addTo(map);

    let marker: MarkerLike | null = null;
    let selectedAddress = "";

    const locateBtn = document.getElementById("locateBtn");
    locateBtn?.addEventListener("click", () => {
      if (!navigator.geolocation) {
        alert("La géolocalisation n'est pas supportée par votre navigateur");
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          map.setView([pos.coords.latitude, pos.coords.longitude], 16);
        },
        () => {
          alert("Impossible d'obtenir votre position. Veuillez autoriser la géolocalisation.");
        },
        { enableHighAccuracy: true }
      );
    });

    map.on("click", async (e: { latlng: { lat: number; lng: number } }) => {
      const lat = e.latlng.lat;
      const lng = e.latlng.lng;
      (document.getElementById("selectedLat") as HTMLInputElement).value = String(lat);
      (document.getElementById("selectedLng") as HTMLInputElement).value = String(lng);
      if (marker) marker.setLatLng(e.latlng);
      else marker = L.marker(e.latlng).addTo(map);
      (document.getElementById("locationInfo") as HTMLElement).textContent = "Récupération de l'adresse...";
      try {
        const resp = await fetch(
          `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=fr`,
          { headers: { "User-Agent": "GuyancourtSignalements/1.0" } }
        );
        const data = await resp.json();
        selectedAddress = data.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        (document.getElementById("locationInfo") as HTMLElement).textContent = "📍 " + selectedAddress;
      } catch {
        selectedAddress = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
        (document.getElementById("locationInfo") as HTMLElement).textContent = "📍 " + selectedAddress;
      }
    });

    const uploadBox = document.getElementById("uploadBox");
    const fileInput = document.getElementById("attachments") as HTMLInputElement;
    if (uploadBox && fileInput) {
      uploadBox.onclick = () => fileInput.click();
      uploadBox.addEventListener("dragover", (e) => {
        e.preventDefault();
        uploadBox.classList.add("dragover");
      });
      uploadBox.addEventListener("dragleave", (e) => {
        e.preventDefault();
        uploadBox.classList.remove("dragover");
      });
      uploadBox.addEventListener("drop", (e) => {
        e.preventDefault();
        uploadBox.classList.remove("dragover");
        if (e.dataTransfer?.files?.length) {
          fileInput.files = e.dataTransfer.files;
          previewImages(fileInput);
        }
      });
      fileInput.addEventListener("change", () => previewImages(fileInput));
    }

    function previewImages(input: HTMLInputElement) {
      const preview = document.getElementById("imagePreview");
      const placeholder = document.getElementById("uploadPlaceholder");
      if (!preview || !placeholder) return;
      preview.innerHTML = "";
      if (input.files?.length) {
        placeholder.style.display = "none";
        Array.from(input.files).forEach((file) => {
          const reader = new FileReader();
          reader.onload = (ev) => {
            const img = document.createElement("img");
            img.src = (ev.target?.result as string) ?? "";
            img.style.cssText = "width:100%;max-width:120px;border-radius:4px;border:1px solid #ddd;";
            preview.appendChild(img);
          };
          reader.readAsDataURL(file);
        });
      } else {
        placeholder.style.display = "block";
      }
    }

    const inputEl = document.getElementById("input") as HTMLTextAreaElement;
    if (inputEl) inputEl.value = localStorage.getItem("complaint") || "";
    inputEl?.addEventListener("input", () => localStorage.setItem("complaint", inputEl.value));

    const expandBtn = document.getElementById("expandBtn");
    expandBtn?.addEventListener("click", async () => {
      const input = (document.getElementById("input") as HTMLTextAreaElement).value;
      const attachments = document.getElementById("attachments") as HTMLInputElement;
      const hasAttachments = !!(attachments?.files?.length);
      const lat = (document.getElementById("selectedLat") as HTMLInputElement).value;
      const lng = (document.getElementById("selectedLng") as HTMLInputElement).value;
      if (!lat || !lng) {
        alert("Veuillez d'abord cliquer sur la carte pour définir la localisation du problème.");
        return;
      }
      if (!input.trim()) {
        alert("Veuillez d'abord décrire votre problème.");
        return;
      }
      (expandBtn as HTMLButtonElement).disabled = true;
      expandBtn.innerHTML = "Rédaction en cours...<span class='loading'></span>";
      try {
        const res = await fetch("/api/expand", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json", "X-Access-Key": accessKey },
          body: JSON.stringify({
            complaint: input,
            hasAttachments,
            address: selectedAddress,
            lat,
            lng,
          }),
        });
        const data = await res.json();
        if (data.success) {
          const parts = data.expanded.split("===ENGLISH===");
          (document.getElementById("complaint") as HTMLTextAreaElement).value = parts[0].trim();
          (document.getElementById("expanded") as HTMLTextAreaElement).value = parts[1]?.trim() ?? "";
        } else {
          alert("Erreur : " + (data.error || "Échec de la rédaction"));
        }
      } catch {
        alert("Erreur de connexion. Veuillez réessayer.");
      } finally {
        (expandBtn as HTMLButtonElement).disabled = false;
        expandBtn.innerHTML = "Rédiger la lettre";
      }
    });

    const sendBtn = document.getElementById("sendBtn");
    const statusDiv = document.getElementById("statusMessage");
    sendBtn?.addEventListener("click", async () => {
      const complaint = (document.getElementById("complaint") as HTMLTextAreaElement).value;
      const attachments = document.getElementById("attachments") as HTMLInputElement;
      const hasAttachments = !!(attachments?.files?.length);
      if (!complaint.trim()) {
        alert("Veuillez d'abord rédiger la lettre formelle.");
        return;
      }
      if (!hasAttachments && !confirm("Aucune photo jointe. Envoyer quand même ?")) return;
      if (!confirm("Êtes-vous sûr de vouloir envoyer ce signalement ?")) return;
      const form = document.getElementById("complaintForm") as HTMLFormElement;
      const formData = new FormData(form);
      formData.set("complaint", complaint);
      formData.append("lat", (document.getElementById("selectedLat") as HTMLInputElement).value);
      formData.append("lng", (document.getElementById("selectedLng") as HTMLInputElement).value);
      if (statusDiv) statusDiv.innerHTML = "<span style='color:#666'>Envoi en cours...</span>";
      try {
        const res = await fetch("/api/send?key=" + encodeURIComponent(accessKey), {
          method: "POST",
          credentials: "include",
          headers: { "X-Access-Key": accessKey },
          body: formData,
        });
        const data = await res.json();
        if (data.success) {
          if (statusDiv) statusDiv.innerHTML = "<span style='color:green'>" + data.message + "</span>";
          (document.getElementById("input") as HTMLTextAreaElement).value = "";
          (document.getElementById("complaint") as HTMLTextAreaElement).value = "";
          (document.getElementById("expanded") as HTMLTextAreaElement).value = "";
          localStorage.removeItem("complaint");
        } else {
          if (statusDiv) statusDiv.innerHTML = "<span style='color:red'>" + data.message + "</span>";
        }
      } catch {
        if (statusDiv) statusDiv.innerHTML = "<span style='color:red'>Erreur de connexion. Veuillez réessayer.</span>";
      }
    });
  }, [accessKey]);

  useEffect(() => {
    let cancelled = false;
    function run() {
      if (cancelled) return;
      const L = (window as unknown as { L?: LeafletLike }).L;
      if (L && document.getElementById("map-root")) {
        runScript();
        return;
      }
      setTimeout(run, 100);
    }
    run();
    return () => {
      cancelled = true;
    };
  }, [runScript]);

  return null;
}
