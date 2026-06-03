from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI

from .inference import get_embedding_service
from .reranker import get_reranker_service
from .schemas import BatchEmbedItem, BatchEmbedRequest, BatchEmbedResponse, BatchInferSkillsItem, BatchInferSkillsRequest, BatchInferSkillsResponse, EmbedRequest, EmbedResponse, HealthResponse, InferSkillsRequest, InferSkillsResponse, InferredTechnicalSkill, MatchRequest, MatchResponse, RerankRequest, RerankResponse, RerankResponseItem
from .skill_inference import infer_skills


@asynccontextmanager
async def lifespan(_: FastAPI):
    get_embedding_service()
    yield


app = FastAPI(title="DevSpot CamemBERT Service", version="1.0.0", lifespan=lifespan)


@app.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    service = get_embedding_service()
    reranker = get_reranker_service()

    return HealthResponse(
        status="ok",
        model=service.model_name,
        dimension=service.embedding_dimension(),
        reranker_available=reranker.available,
        reranker_model=reranker.model_path,
    )


@app.post("/embed", response_model=EmbedResponse)
def embed(request: EmbedRequest) -> EmbedResponse:
    service = get_embedding_service()
    embedding, normalized_text = service.embed_text(request.text)

    return EmbedResponse(
        embedding=embedding,
        dimension=len(embedding),
        normalized_text=normalized_text,
    )


@app.post("/embed-batch", response_model=BatchEmbedResponse)
def embed_batch(request: BatchEmbedRequest) -> BatchEmbedResponse:
    service = get_embedding_service()
    embeddings, normalized_texts = service.embed_texts(request.texts)

    return BatchEmbedResponse(
        items=[
            BatchEmbedItem(
                embedding=embedding,
                dimension=len(embedding),
                normalized_text=normalized_text,
            )
            for embedding, normalized_text in zip(embeddings, normalized_texts, strict=True)
        ]
    )


@app.post("/match", response_model=MatchResponse)
def match(request: MatchRequest) -> MatchResponse:
    service = get_embedding_service()
    semantic_score, offer_dimension, candidate_dimension, normalized_offer, normalized_candidate = service.semantic_similarity(
        request.offer_text,
        request.candidate_text,
    )

    return MatchResponse(
        semantic_score=semantic_score,
        offer_dimension=offer_dimension,
        candidate_dimension=candidate_dimension,
        normalized_offer_text=normalized_offer,
        normalized_candidate_text=normalized_candidate,
    )


@app.post("/infer-skills", response_model=InferSkillsResponse)
def infer_candidate_skills(request: InferSkillsRequest) -> InferSkillsResponse:
    inferred_soft_skills, inferred_transferable_skills, inferred_technical_skills, confidence, normalized_text = infer_skills(request.text)

    return InferSkillsResponse(
        inferred_soft_skills=inferred_soft_skills,
        inferred_transferable_skills=inferred_transferable_skills,
        inferred_technical_skills=[InferredTechnicalSkill(**skill) for skill in inferred_technical_skills],
        confidence=confidence,
        normalized_text=normalized_text,
    )


@app.post("/infer-skills-batch", response_model=BatchInferSkillsResponse)
def infer_candidate_skills_batch(request: BatchInferSkillsRequest) -> BatchInferSkillsResponse:
    items: list[BatchInferSkillsItem] = []

    for text in request.texts:
        inferred_soft_skills, inferred_transferable_skills, inferred_technical_skills, confidence, normalized_text = infer_skills(text)
        items.append(BatchInferSkillsItem(
            inferred_soft_skills=inferred_soft_skills,
            inferred_transferable_skills=inferred_transferable_skills,
            inferred_technical_skills=[InferredTechnicalSkill(**skill) for skill in inferred_technical_skills],
            confidence=confidence,
            normalized_text=normalized_text,
        ))

    return BatchInferSkillsResponse(items=items)


@app.post("/rerank", response_model=RerankResponse)
def rerank(request: RerankRequest) -> RerankResponse:
    service = get_reranker_service()
    scores = service.score_features([item.features for item in request.items])

    return RerankResponse(
        items=[
            RerankResponseItem(candidate_id=item.candidate_id, score=score)
            for item, score in zip(request.items, scores, strict=True)
        ]
    )
