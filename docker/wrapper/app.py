"""Minimal edge-tts HTTP wrapper implementing the TTS module contract:

GET  /v1/voices -> [{"id": "...", "lang": "...", "gender": "..."}, ...]
POST /v1/tts    {"text": "...", "voice": "...", "rate": "+0%", "pitch": "+0Hz"}
             -> 200 audio/mpeg (MP3 binary)
"""

from __future__ import annotations

import io

import edge_tts
from fastapi import FastAPI, Response
from pydantic import BaseModel, Field

app = FastAPI(title="edge-tts wrapper")


class TtsBody(BaseModel):
    text: str = Field(min_length=1)
    voice: str = "ru-RU-SvetlanaNeural"
    rate: str | None = None
    pitch: str | None = None


@app.get("/v1/voices")
async def voices() -> list[dict]:
    raw = await edge_tts.list_voices()
    return [
        {
            "id": entry["ShortName"],
            "lang": entry.get("Locale"),
            "gender": entry.get("Gender"),
        }
        for entry in raw
    ]


@app.post("/v1/tts")
async def tts(body: TtsBody) -> Response:
    communicate = edge_tts.Communicate(
        body.text,
        body.voice,
        rate=body.rate or "+0%",
        pitch=body.pitch or "+0Hz",
    )
    buf = io.BytesIO()
    async for chunk in communicate.stream():
        if chunk["type"] == "audio":
            buf.write(chunk["data"])
    data = buf.getvalue()
    if not data:
        return Response(status_code=502)
    return Response(content=data, media_type="audio/mpeg")
