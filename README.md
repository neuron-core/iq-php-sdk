# Neuron Cloud SDK

This project is an empty scaffolding of a PHP package.

My goal is to create a PHP SDK connecting Neuron AI (../neuron-ai) to a durable orchestration platform that we will call "Neuron Cloud".

I want to design this SDK as the first building block to build a durable orchestration SaaS product like Restate, Inngest, Temporal. Every platform in this space provide their durable framework (it's Neuron AI in our case) and a SaaS platform that works as the orchestrator, and it is the business part of this architecture (the Neuron Cloud).

I want to adopt a bottom-up approach to build this project, starting from the analysis if Neuron, design how we can control workflow in a PHP environment, so it will lead to the creation of a protocol, and then we can build the platform to operate with this protocol.

I don't have any experience on durable architectures, and I'm not able to understand if and how the Neuron AI durable Workflow is already ready to allow the creation of such a service on top.

So the first task is to investigate how the Neuron AI Workflow offers the appropriate durable features and control APIs, and create a document `DURABLE_PLATFORM_READINESS.md` to map if and how Neuron allows us to build such a service on top, what are pros and cons of the current architecture for the creation of this orchestration platform.

Start the document with a little introduction of how similar platform like Restate and Temporal design their framework in order to be connected to the platform. Then map the Neuron analysis.

One of the most important point of Neuron is that it keeps **workflow state in your application's durable persistence** (via Neuron's
`PersistenceInterface`, using SQL, Redis, or another suitable backend). So workflow state, prompts, and tool I/O stay on the application. This already removes all the concern for transmitting sensible data outside of the application. We basically should think only on the Orchestration layer.

Observability is configured separately in Neuron.
