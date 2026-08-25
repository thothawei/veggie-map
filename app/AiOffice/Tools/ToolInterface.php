<?php

namespace App\AiOffice\Tools;

/**
 * 規格第 15 節。每個工具都要能自我描述（給 LLM 看的 schema）、宣告風險等級，
 * 並且真的能執行。
 *
 * 真正的五個工具組（File／Git／Terminal／Docker／Database）由 ToolRegistrar
 * 掛上；權限表跟工具用同一組動作名稱。
 */
interface ToolInterface
{
    /**
     * 動作名稱，例如 read_file／git_commit。這也是 agent_permissions.ability 的值
     * ——權限表跟工具用同一組名稱，不做第二套對照，少一層對不上的機會。
     */
    public function name(): string;

    /**
     * 所屬工具組：file／git／terminal／docker／database。
     * agent_tools 存的是工具組（一個 Agent 拿到 git 就拿到 git 底下所有動作），
     * 但每個動作各自受 agent_permissions 管，所以「拿到 git」不等於「可以 git push」。
     */
    public function toolset(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema，直接送給 LLM 當 inputSchema。 */
    public function inputSchema(): array;

    /** low／medium／high／critical（規格第 22 節）。 */
    public function riskLevel(): string;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> 會被序列化成 tool_result 回給模型。
     */
    public function execute(array $input, ToolContext $context): array;
}
