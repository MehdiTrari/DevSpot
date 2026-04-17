from __future__ import annotations

from pydantic import BaseModel, Field


class HealthResponse(BaseModel):
    status: str
    model: str
    dimension: int


class EmbedRequest(BaseModel):
    text: str = Field(min_length=1)


class BatchEmbedRequest(BaseModel):
    texts: list[str] = Field(min_length=1)


class EmbedResponse(BaseModel):
    embedding: list[float]
    dimension: int
    normalized_text: str


class BatchEmbedItem(BaseModel):
    embedding: list[float]
    dimension: int
    normalized_text: str


class BatchEmbedResponse(BaseModel):
    items: list[BatchEmbedItem]


class MatchRequest(BaseModel):
    offer_text: str = Field(min_length=1)
    candidate_text: str = Field(min_length=1)


class MatchResponse(BaseModel):
    semantic_score: float
    offer_dimension: int
    candidate_dimension: int
    normalized_offer_text: str
    normalized_candidate_text: str


class InferSkillsRequest(BaseModel):
    text: str = Field(min_length=1)


class BatchInferSkillsRequest(BaseModel):
    texts: list[str] = Field(min_length=1)


class InferredTechnicalSkill(BaseModel):
    skill: str
    level: str
    confidence: float


class InferSkillsResponse(BaseModel):
    inferred_soft_skills: list[str]
    inferred_transferable_skills: list[str]
    inferred_technical_skills: list[InferredTechnicalSkill]
    confidence: dict[str, float]
    normalized_text: str


class BatchInferSkillsItem(BaseModel):
    inferred_soft_skills: list[str]
    inferred_transferable_skills: list[str]
    inferred_technical_skills: list[InferredTechnicalSkill]
    confidence: dict[str, float]
    normalized_text: str


class BatchInferSkillsResponse(BaseModel):
    items: list[BatchInferSkillsItem]
